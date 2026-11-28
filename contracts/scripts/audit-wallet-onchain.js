// READ-ONLY: check on-chain stakes, pending reward, cooldown and recent RewardPaid logs for one wallet.
const { ethers, id, zeroPadValue } = require('ethers');

const RPC = 'https://bsc-dataseed1.binance.org';
const ENGINE = '0x93e75234026aa3942624dd61dc4ef2acf958e1de';
const WALLET = '0xc75bb1a06abbe740d26d2f47e6639491a25236e6';
const FROM_BLOCK = 133903000;

const sel = (sig) => id(sig).slice(0, 10);
const pad = (hex) => hex.replace(/^0x/, '').padStart(64, '0');

async function call(provider, data) {
  return provider.call({ to: ENGINE, data });
}

(async () => {
  const provider = new ethers.JsonRpcProvider(RPC, 56, { staticNetwork: true });
  const latest = await provider.getBlockNumber();
  console.log('latest block', latest);

  const nowSec = Math.floor(Date.now() / 1000);
  const nextRaw = await call(provider, sel('nextAllowedClaimAt(address)') + pad(WALLET));
  const nextAt = Number(BigInt(nextRaw));
  console.log('nextAllowedClaimAt', nextAt, nextAt ? new Date(nextAt * 1000).toISOString() : 'none', 'now', new Date(nowSec * 1000).toISOString());

  const countRaw = await call(provider, sel('stakeCount(address)') + pad(WALLET));
  const count = Number(BigInt(countRaw));
  console.log('stakeCount', count);
  for (let i = 0; i < count; i++) {
    const r = await call(provider, sel('pendingRewardRace(address,uint256)') + pad(WALLET) + pad('0x' + i.toString(16)));
    console.log(`stake ${i} pendingRewardRace`, ethers.formatUnits(BigInt(r), 18));
  }

  const topic0 = id('RewardPaid(address,uint256,uint256,uint256,uint256,uint256)');
  const topic1 = zeroPadValue(WALLET, 32);
  const logs = [];
  for (let from = FROM_BLOCK; from <= latest; from += 5000) {
    const to = Math.min(from + 4999, latest);
    try {
      const part = await provider.getLogs({ address: ENGINE, fromBlock: from, toBlock: to, topics: [topic0, topic1] });
      logs.push(...part);
    } catch (e) {
      console.log('getLogs failed', from, to, e.shortMessage || e.message);
    }
  }
  console.log('RewardPaid logs for wallet since', FROM_BLOCK, ':', logs.length);
  for (const l of logs) {
    const block = await provider.getBlock(l.blockNumber);
    const [stakeIndex, rewardUsdt, rewardRace, daysPaid, price] = ethers.AbiCoder.defaultAbiCoder().decode(
      ['uint256', 'uint256', 'uint256', 'uint256', 'uint256'],
      l.data,
    );
    console.log(
      'block', l.blockNumber,
      'time', new Date(block.timestamp * 1000).toISOString(),
      'stake', stakeIndex.toString(),
      'rewardUsdt', ethers.formatUnits(rewardUsdt, 18),
      'rewardRace', ethers.formatUnits(rewardRace, 18),
      'days', daysPaid.toString(),
      'tx', l.transactionHash,
    );
  }
})().catch((e) => {
  console.error('ERR', e.shortMessage || e.message);
  process.exit(1);
});
