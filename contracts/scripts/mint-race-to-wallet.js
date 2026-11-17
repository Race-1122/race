/**
 * Call RaceCoin.mint(to, amountWei) using an isMinter EOA.
 * Usage (env):
 *   RACE_TOKEN_CONTRACT=0x...
 *   RACE_INCOME_MINTER_PRIVATE_KEY=0x...
 *   BSC_RPC_URL=https://...
 *   MINT_TO=0x...
 *   MINT_AMOUNT_WEI=...
 */
const { ethers } = require('ethers');

async function main() {
  const rpc = process.env.BSC_RPC_URL || process.env.RPC_URL;
  const pk = process.env.RACE_INCOME_MINTER_PRIVATE_KEY || process.env.PRIVATE_KEY;
  const token = process.env.RACE_TOKEN_CONTRACT || process.env.RACE_TOKEN;
  const to = process.env.MINT_TO;
  const amountWei = process.env.MINT_AMOUNT_WEI;

  if (!rpc || !pk || !token || !to || !amountWei) {
    throw new Error('Need BSC_RPC_URL, RACE_INCOME_MINTER_PRIVATE_KEY, RACE_TOKEN_CONTRACT, MINT_TO, MINT_AMOUNT_WEI');
  }

  const provider = new ethers.JsonRpcProvider(rpc);
  const wallet = new ethers.Wallet(pk.startsWith('0x') ? pk : `0x${pk}`, provider);
  const abi = [
    'function mint(address to,uint256 amount)',
    'function isMinter(address) view returns (bool)',
  ];
  const race = new ethers.Contract(token, abi, wallet);

  const ok = await race.isMinter(wallet.address);
  if (!ok) {
    throw new Error(`Wallet ${wallet.address} is not RaceCoin isMinter — Multisig must setMinter first`);
  }

  const tx = await race.mint(to, amountWei);
  const receipt = await tx.wait();
  process.stdout.write(
    JSON.stringify({
      ok: true,
      txHash: receipt.hash,
      to,
      amountWei: amountWei.toString(),
      minter: wallet.address,
    }) + '\n',
  );
}

main().catch((e) => {
  process.stderr.write(String(e && e.message ? e.message : e) + '\n');
  process.exit(1);
});
