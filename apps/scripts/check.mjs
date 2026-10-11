import { readFile, readdir } from "node:fs/promises";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
const root = fileURLToPath(new URL("../", import.meta.url));
const name = process.argv[2];
const { Linter } = await import(
  `${root}${name}/node_modules/eslint/lib/api.js`
);
const linter = new Linter();
const globals = Object.fromEntries(
  [
    "uni",
    "plus",
    "window",
    "document",
    "fetch",
    "Blob",
    "URL",
    "navigator",
    "console",
    "setTimeout",
    "clearTimeout",
    "setInterval",
    "clearInterval",
    "defineProps",
    "defineEmits",
    "defineExpose",
  ].map((g) => [g, "readonly"]),
);
function lint(source, file) {
  const issues = linter.verify(source, [
    {
      languageOptions: { ecmaVersion: "latest", sourceType: "module", globals },
      rules: { "no-undef": "error" },
    },
  ]);
  assert.equal(
    issues.length,
    0,
    `${file}: ${issues.map((i) => `${i.line}: ${i.message}`).join("; ")}`,
  );
}

const manifest = JSON.parse(
  await readFile(`${root}${name}/src/manifest.json`, "utf8"),
);
const other = JSON.parse(
  await readFile(
    `${root}${name === "consumer" ? "broker" : "consumer"}/src/manifest.json`,
    "utf8",
  ),
);
assert.match(manifest.appid, /^__UNI__[A-Z0-9]+$/);
assert.notEqual(manifest.appid, other.appid);
assert.notEqual(
  manifest["app-plus"].distribute.android.packagename,
  other["app-plus"].distribute.android.packagename,
);
const pages = JSON.parse(
  await readFile(`${root}${name}/src/pages.json`, "utf8"),
);
for (const page of pages.pages)
  await readFile(`${root}${name}/src/${page.path}.vue`);
const { parse, compileScript, compileTemplate } = await import(
  `${root}${name}/node_modules/@vue/compiler-sfc/dist/compiler-sfc.cjs.js`
);
for (const dir of [`${root}${name}/src/pages`, `${root}shared`])
  for (const file of await readdir(dir))
    if (file.endsWith(".vue")) {
      const source = await readFile(`${dir}/${file}`, "utf8");
      const { descriptor, errors } = parse(source, { filename: file });
      assert.equal(errors.length, 0, `${file}: ${errors}`);
      let bindings = {};
      if (descriptor.scriptSetup) lint(descriptor.scriptSetup.content, file);
      if (descriptor.scriptSetup)
        bindings = compileScript(descriptor, { id: file }).bindings;
      const r = compileTemplate({
        source: descriptor.template.content,
        filename: file,
        id: file,
        compilerOptions: { bindingMetadata: bindings },
      });
      assert.equal(r.errors.length, 0, `${file}: ${r.errors}`);
    }
const result = spawnSync(
  process.execPath,
  ["--test", `${root}shared/api.test.mjs`, `${root}shared/domain.test.mjs`, `${root}shared/native-file.test.mjs`, `${root}shared/sms.test.mjs`, `${root}shared/account-panel.test.mjs`, `${root}shared/chat.test.mjs`, `${root}shared/post-boat.test.mjs`, `${root}shared/boat-parameters.test.mjs`],
  { stdio: "inherit" },
);
assert.equal(result.status, 0);
for (const file of await readdir(`${root}shared`))
  if (file.endsWith(".js"))
    lint(await readFile(`${root}shared/${file}`, "utf8"), file);
console.log(
  `${name}: manifests, routes, Vue templates and domain checks passed`,
);
