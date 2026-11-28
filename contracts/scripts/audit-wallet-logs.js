// READ-ONLY: raw eth_getLogs for RewardPaid events of one wallet on the Engine (last ~3 days).
const { ethers, id } = require('ethers');

const RPC = 'https://bsc-dataseed1.binance.org';
const ENGINE = '0x93e75234026aa3942624dd61dc4ef2acf958e1de';
const WALLET = '0xc75bb1a06abbe740d26d2f47e6639491a25236e6';

(async () => {
  const provider = new ethers.JsonRpcProvider(RPC, 56, { staticNetwork: true });
  const latest = await provider.getBlockNumber();
  const from0 = 133950000;
  const topic0 = id('RewardPaid(address,uint256,uint256,uint256,uint256,uint256)');
  const topic1 = '0x' + WALLET.slice(2).padStart(64, '0');
  let total = 0;
  for (let from = from0; from <= latest; from += 2000) {
    const to = Math.min(from + 1999, latest);
    try {
      const logs = await provider.send('eth_getLogs', [
        { address: ENGINE, fromBlock: '0x' + from.toString(16), toBlock: '0x' + to.toString(16), topics: [topic0, topic1] },
      ]);
      for (const l of logs) {
        total++;
        const block = await provider.getBlock(parseInt(l.blockNumber, 16));
        const [stakeIndex, rewardUsdt, rewardRace, daysPaid] = ethers.AbiCoder.defaultAbiCoder().decode(
          ['uint256', 'uint256', 'uint256', 'uint256', 'uint256'],
          l.data,
        );
        console.log(
          'block', parseInt(l.blockNumber, 16),
          'utc', new Date(block.timestamp * 1000).toISOString(),
          'ist', new Date(block.timestamp * 1000 + 19800000).toISOString().replace('T', ' ').slice(0, 19),
          'stake', stakeIndex.toString(),
          'rewardUsdt', ethers.formatUnits(rewardUsdt, 18),
          'rewardRace', ethers.formatUnits(rewardRace, 18),
          'days', daysPaid.toString(),
          'tx', l.transactionHash,
        );
      }
    } catch (e) {
      console.log('chunk failed', from, to, (e.shortMessage || e.message || '').slice(0, 120));
    }
  }
  console.log('total RewardPaid logs for wallet:', total, 'latest', latest);
})().catch((e) => {
  console.error('ERR', e.shortMessage || e.message);
  process.exit(1);
});
