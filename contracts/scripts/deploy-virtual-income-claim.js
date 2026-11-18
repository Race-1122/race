/**
 * Deploy RaceVirtualIncomeClaim then Multisig must:
 *   RaceCoin.setMinter(claimAddress, true)
 *
 * Env:
 *   RACE_TOKEN_CONTRACT / race from deployments
 *   VIRTUAL_INCOME_CLAIM_SIGNER (EOA that Laravel uses to EIP-712 sign)
 *   CONFIRM_VIRTUAL_INCOME_CLAIM_DEPLOY=YES
 */
const hre = require('hardhat');
const fs = require('fs');
const path = require('path');

async function main() {
  if (process.env.CONFIRM_VIRTUAL_INCOME_CLAIM_DEPLOY !== 'YES') {
    throw new Error('Set CONFIRM_VIRTUAL_INCOME_CLAIM_DEPLOY=YES');
  }

  const [deployer] = await hre.ethers.getSigners();
  const race =
    process.env.RACE_TOKEN_CONTRACT ||
    process.env.RACE_TOKEN ||
    '0xa29683442d9221Df0Eb7AFF3932c1E165FFa93EB';
  const signer =
    process.env.VIRTUAL_INCOME_CLAIM_SIGNER ||
    process.env.INCOME_VAULT_SETTLEMENT_SIGNER ||
    deployer.address;
  const owner =
    process.env.RACE_MULTISIG_CONTRACT ||
    '0x81BffBF2C2a258E662e9df0a0f6e34150b33F3df';

  console.log('deployer', deployer.address);
  console.log('race', race);
  console.log('signer', signer);
  console.log('owner(multisig)', owner);

  const F = await hre.ethers.getContractFactory('RaceVirtualIncomeClaim');
  const c = await F.deploy(owner, race, signer);
  await c.waitForDeployment();
  const addr = await c.getAddress();
  console.log('RaceVirtualIncomeClaim', addr);

  const outDir = path.join(__dirname, '..', 'deployments', hre.network.name);
  fs.mkdirSync(outDir, { recursive: true });
  const out = {
    network: hre.network.name,
    chainId: (await hre.ethers.provider.getNetwork()).chainId.toString(),
    raceVirtualIncomeClaim: addr,
    raceToken: race,
    signer,
    owner,
    deployedAt: new Date().toISOString(),
    nextStep: `Multisig 3-of-5: RaceCoin.setMinter(${addr}, true)`,
  };
  fs.writeFileSync(path.join(outDir, 'virtual-income-claim.json'), JSON.stringify(out, null, 2));
  console.log('wrote', path.join(outDir, 'virtual-income-claim.json'));
  console.log('NEXT: Multisig submit RaceCoin.setMinter(claim, true)');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
