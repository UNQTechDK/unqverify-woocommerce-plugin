const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const rootDir = path.resolve(__dirname, "..");
const stagingParent = path.join(rootDir, ".build");
const pluginDirName = "aldersverificering-woocommerce";
const stagingDir = path.join(stagingParent, pluginDirName);
const zipPath = path.join(rootDir, `${pluginDirName}.zip`);

const excludedNames = new Set([
  ".git",
  ".github",
  ".agents",
  "node_modules",
  "vendor",
  "tests",
  "scripts",
  ".build",
  "test-results",
  "playwright-report",
]);

const excludedFiles = new Set([
  ".gitignore",
  ".wp-env.json",
  ".phpunit.result.cache",
  "package.json",
  "pnpm-lock.yaml",
  "phpunit.xml.dist",
  "playwright.config.js",
  "MANUAL_TEST_SUITE.md",
  "skills-lock.json",
  "composer.lock",
  `${pluginDirName}.zip`,
]);

function resetDir(dirPath) {
  fs.rmSync(dirPath, { recursive: true, force: true });
  fs.mkdirSync(dirPath, { recursive: true });
}

function copyRecursive(sourcePath, destinationPath) {
  const stat = fs.statSync(sourcePath);

  if (stat.isDirectory()) {
    fs.mkdirSync(destinationPath, { recursive: true });
    for (const entry of fs.readdirSync(sourcePath)) {
      if (excludedNames.has(entry)) {
        continue;
      }
      copyRecursive(
        path.join(sourcePath, entry),
        path.join(destinationPath, entry),
      );
    }
    return;
  }

  if (excludedFiles.has(path.basename(sourcePath))) {
    return;
  }

  fs.copyFileSync(sourcePath, destinationPath);
}

try {
  fs.rmSync(zipPath, { force: true });
  resetDir(stagingDir);
  for (const entry of fs.readdirSync(rootDir)) {
    if (excludedNames.has(entry)) {
      continue;
    }
    copyRecursive(path.join(rootDir, entry), path.join(stagingDir, entry));
  }

  execFileSync("zip", ["-r", zipPath, pluginDirName], {
    cwd: stagingParent,
    stdio: "inherit",
  });

  console.log(`Created ${zipPath}`);
} catch (error) {
  console.error(error.message || error);
  process.exit(1);
}
