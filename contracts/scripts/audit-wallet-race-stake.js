// READ-ONLY: RaceWalletRaceStake (wallet stake contract) state for one wallet.
const { ethers, id } = require('ethers');

const RPC = 'https://bsc-dataseed1.binance.org';
const WALLET_RACE_STAKE = '0xbcb76726d87e0c794ae34bbf692c2d204ef9b29c';
const WALLET = '0xc75bb1a06abbe740d26d2f47e6639491a25236e6';

const sel = (sig) => id(sig).slice(0, 10);
const pad = (hex) => hex.replace(/^0x/, '').padStart(64, '0');

(async () => {
  const provider = new ethers.JsonRpcProvider(RPC, 56, { staticNetwork: true });
  const call = (data) => provider.call({ to: WALLET_RACE_STAKE, data });

  const count = Number(BigInt(await call(sel('stakeCount(address)') + pad(WALLET))));
  console.log('wallet-race stakeCount', count);
  for (let i = 0; i < count; i++) {
    const pending = await call(sel('pendingRewardRace(address,uint256)') + pad(WALLET) + pad('0x' + i.toString(16)));
    console.log(`wallet-race stake ${i} pendingRewardRace`, ethers.formatUnits(BigInt(pending), 18));
  }
  const next = Number(BigInt(await call(sel('nextAllowedClaimAt(address)') + pad(WALLET))));
  console.log('wallet-race nextAllowedClaimAt', next, next ? new Date(next * 1000).toISOString() : 'none');
})().catch((e) => {
  console.error('ERR', e.shortMessage || e.message);
  process.exit(1);
});
