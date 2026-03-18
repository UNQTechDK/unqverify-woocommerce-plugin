#!/usr/bin/env node
/**
 * Compiles all .po files in languages/ to .mo binaries.
 * Run via: pnpm make:mo
 */

import { readFileSync, writeFileSync, readdirSync } from "fs";
import { join, dirname } from "path";
import { fileURLToPath } from "url";
import gettextParser from "gettext-parser";

const __dirname = dirname(fileURLToPath(import.meta.url));
const langDir = join(__dirname, "..", "languages");

const poFiles = readdirSync(langDir).filter((f) => f.endsWith(".po"));

if (poFiles.length === 0) {
  console.log("No .po files found in languages/");
  process.exit(0);
}

for (const file of poFiles) {
  const poPath = join(langDir, file);
  const moPath = poPath.replace(/\.po$/, ".mo");

  const po = gettextParser.po.parse(readFileSync(poPath));
  writeFileSync(moPath, gettextParser.mo.compile(po));

  console.log(`Compiled: ${file} → ${file.replace(/\.po$/, ".mo")}`);
}
