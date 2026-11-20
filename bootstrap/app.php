<?php

use App\Console\Commands\DecentralizationCheckCommand;
use App\Console\Commands\IncomeMigrationReportCommand;
use App\Console\Commands\IncomeReconcileOnchainCommand;
use App\Console\Commands\IndexIncomeVaultEventsCommand;
use App\Console\Commands\IncomeRepairIndexCommand;
use App\Console\Commands\IncomeReconcilePendingCompoundsCommand;
use App\Console\Commands\IncomeReconcileWalletsCommand;
use App\Console\Commands\IncomeDiagnoseNetworkRoiCommand;
use App\Console\Commands\IncomeDiagnoseRoiCommand;
use App\Console\Commands\IncomePayCommunityLeadershipCommand;
use App\Console\Commands\IncomePayCommunityLeadershipRank11MonthlyCommand;
use App\Console\Commands\IncomePayRoiCommand;
use App\Console\Commands\IncomePurgeCronDayCommand;
use App\Console\Commands\IncomeReleaseStakeEmisCommand;
use App\Console\Commands\IncomeRolloverHoldingsCommand;
use App\Console\Commands\IndexBlockchainEventsCommand;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectCentralizedRewardWrites;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        IncomePayRoiCommand::class,
        IncomePayCommunityLeadershipCommand::class,
        IncomePayCommunityLeadershipRank11MonthlyCommand::class,
        IncomeReleaseStakeEmisCommand::class,
        IncomeRolloverHoldingsCommand::class,
        IncomeDiagnoseRoiCommand::class,
        IncomeDiagnoseNetworkRoiCommand::class,
        IncomePurgeCronDayCommand::class,
        IndexBlockchainEventsCommand::class,
        IncomeReconcileWalletsCommand::class,
        IncomeReconcilePendingCompoundsCommand::class,
        IncomeReconcileOnchainCommand::class,
        IncomeMigrationReportCommand::class,
        IndexIncomeVaultEventsCommand::class,
        IncomeRepairIndexCommand::class,
        DecentralizationCheckCommand::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RejectCentralizedRewardWrites::class,
        ]);

        $middleware->alias([
            'referral.register' => \App\Http\Middleware\RequireReferralForRegister::class,
        ]);

        // TokenPocket / in-app WebView often omit CSRF cookies on fetch.
        // Wallet login is still protected by signed nonce + throttle.
        $middleware->validateCsrfTokens(except: [
            'wallet-auth/nonce',
            'wallet-auth/login',
            'wallet-auth/register',
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $incomeTz = (string) config('income.auto_claim.timezone', config('app.timezone', 'Asia/Kolkata'));
        $autoClaimOn = (bool) config('income.auto_claim.enabled', true);
        $hybridIncome = config('blockchain.rewards_engine') !== 'blockchain_only';

        // Daily ROI accrual only. CLAIM IS MANUAL (user button, 07:00–12:00 IST) — no auto-claim cron.
        if ($hybridIncome || $autoClaimOn) {
            $payAt = (string) config('income.auto_claim.pay_roi_at', '00:15');

            $schedule->command('income:pay-roi')
                ->dailyAt($payAt)
                ->timezone($incomeTz)
                ->withoutOverlapping(30);
        }

        if ($hybridIncome) {
            // Community income (leadership) is released once a day at 12:00 (config income.claim.community_release_at).
            $communityAt = (string) config('income.claim.community_release_at', '12:00');
            $schedule->command('income:pay-community-leadership')->dailyAt($communityAt)->timezone($incomeTz);
            $schedule->command('income:pay-community-leadership-rank11-monthly')->monthlyOn(1, '04:00')->timezone($incomeTz);
            $schedule->command('income:release-stake-emis')->dailyAt('02:30')->timezone($incomeTz);
            $schedule->command('income:rollover-holdings')->hourly()->timezone($incomeTz);
        }

        // Recoup stake ROI paid by RACE mint when the Engine later pays the same days (no-op without advances).
        $schedule->command('stake-mint:reconcile')->hourly()->withoutOverlapping(30);

        // 04:00 IST — every Engine stake (1…N) → Virtual Income at that stake's principal × daily ROI.
        $autoAt = (string) config('race_income_mint.stake_claim_auto_at', '04:00');
        $schedule->command('stake-mint:auto-claim')
            ->dailyAt($autoAt)
            ->timezone($incomeTz)
            ->withoutOverlapping(120);

        $schedule->command('race:make-whole-wallet-transfers')->everyMinute()->withoutOverlapping(5);

        if (config('blockchain.indexer.enabled')) {
            $schedule->command('blockchain:index-events')->everyMinute()->withoutOverlapping(4);
            // Safety net: any member with an open on-chain stake but ID still pending gets activated (no re-lock).
            $schedule->command('members:activate-staked-ids')->everyMinute()->withoutOverlapping(4);
            $schedule->command('blockchain:backfill-ico-purchases --blocks=4000')->everyMinute()->withoutOverlapping(3);
            // ICO createStake → Active package / fund ledger (UI-less safety net).
            $schedule->command('blockchain:sync-all-ico-stakes')->everyFiveMinutes()->withoutOverlapping(10);
            $schedule->command('blockchain:backfill-ico-stake-investments')->everyFiveMinutes()->withoutOverlapping(10);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
