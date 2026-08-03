const fs = require("fs");
const path = require("path");

async function main() {
  const gettextParser = await import("gettext-parser");
  const languagesDir = path.resolve(__dirname, "..", "languages");

  if (!fs.existsSync(languagesDir)) {
    console.error(`Languages directory not found: ${languagesDir}`);
    process.exit(1);
  }

  const files = fs
    .readdirSync(languagesDir)
    .filter((file) => file.endsWith(".po"));

  if (files.length === 0) {
    console.log("No .po files found. Nothing to compile.");
    return;
  }

  for (const file of files) {
    const poPath = path.join(languagesDir, file);
    const moPath = poPath.replace(/\.po$/i, ".mo");
    const poBuffer = fs.readFileSync(poPath);
    const parsed = gettextParser.po.parse(poBuffer);
    const moBuffer = gettextParser.mo.compile(parsed);

    fs.writeFileSync(moPath, moBuffer);
    console.log(
      `Compiled ${path.basename(poPath)} -> ${path.basename(moPath)}`,
    );
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
