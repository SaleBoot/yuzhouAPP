import assert from "node:assert/strict";
import {readFile} from "node:fs/promises";
import {createHash, webcrypto} from "node:crypto";
import vm from "node:vm";
import test from "node:test";

const html = await readFile(new URL("../../deploy/app-update.html", import.meta.url), "utf8");
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const appid = "__UNI__E148E73";
const bytes = Buffer.from("verified WGT fixture bytes");
const release = {
  appid, package: "top.ourideas.yuzhou.consumer", version_name: "1.0.3", version_code: 103,
  compiler_version: "5.26", url: "/app-updates/consumer-1.0.3.wgt", bytes: bytes.length,
  sha256: createHash("sha256").update(bytes).digest("hex"), source_commit: "a".repeat(40)
};

function page(options = {}) {
  const elements = Object.fromEntries(["title", "version", "status", "update"].map(id => [id, {textContent: "", disabled: true, addEventListener() {}}]));
  const calls = {fetch: 0, downloads: [], readers: 0, installs: [], restarts: 0};
  const item = {...release, ...options.release};
  const current = {appid: options.appid || appid, version: "1.0.2", versionCode: "102", ...options.current};
  const local = "_doc/yuzhou-update/" + item.appid + "-" + item.version_name + ".wgt";
  const context = {
    document: {getElementById: id => elements[id], addEventListener() {}},
    location: {origin: options.origin || "https://yuzhou.ourideas.top"},
    URL, AbortController, setTimeout, clearTimeout, Uint8Array,
    crypto: options.digestError ? {subtle: {digest: async () => {throw new Error("摘要计算失败");}}} : webcrypto,
    atob: text => Buffer.from(text, "base64").toString("binary"),
    fetch: async (url, settings) => {
      calls.fetch++;
      assert.equal(url, "/app-updates/latest.json");
      assert.equal(settings.redirect, "error");
      assert.equal(settings.credentials, "omit");
      assert.equal(settings.cache, "no-store");
      if (options.fetchError) throw new TypeError("Failed to fetch");
      return {ok: options.httpError ? false : true, json: async () => ({apps: {[item.appid]: item}})};
    }
  };
  context.window = context;
  vm.createContext(context);
  vm.runInContext(script, context, {filename: "app-update.html"});
  context.plus = {
    runtime: {
      appid: current.appid, uniVersion: options.uniVersion === undefined ? "5.26.0" : options.uniVersion,
      getProperty: (id, callback) => {assert.equal(id, current.appid); callback(current);},
      install: (path, settings, success, failure) => {
        calls.installs.push({path, settings});
        if (options.installError) return failure({message: "native rejected"});
        success({appid: item.appid, version: item.version_name, versionCode: String(item.version_code), ...options.installed});
      },
      restart: () => {calls.restarts++;}
    },
    downloader: {
      createDownload: (url, settings, complete) => {
        calls.downloads.push({url, settings});
        return {start: () => complete({filename: options.localPath || local}, options.downloadCode === undefined ? 200 : options.downloadCode)};
      }
    },
    io: {
      convertLocalFileSystemURL: path => "/storage/app/doc/" + path.slice("_doc/".length),
      resolveLocalFileSystemURL: (path, success, failure) => {
        if (options.readError) return failure({message: "not readable"});
        success({file: callback => callback({size: options.fileSize === undefined ? bytes.length : options.fileSize})});
      },
      FileReader: class {
        readAsDataURL() {
          calls.readers++;
          if (options.readerError) return this.onerror({});
          this.onload({target: {result: "data:application/zip;base64," + (options.fileBytes || bytes).toString("base64")}});
        }
      }
    }
  };
  return {context, elements, calls, check: () => context.check(), update: () => context.update()};
}

test("requires known AppID and official origin before fetching metadata", async () => {
  for (const options of [{appid: "other-app"}, {appid: "__proto__"}, {origin: "https://other.example"}]) {
    const p = page(options);
    await p.check(); await p.update();
    assert.equal(p.calls.fetch, 0);
    assert.equal(p.calls.installs.length, 0);
    assert.equal(p.calls.restarts, 0);
  }
});

test("checks metadata and waits for the user before downloading", async () => {
  const p = page();
  await p.check();
  assert.equal(p.elements.update.textContent, "更新并重新打开");
  assert.equal(p.elements.update.disabled, false);
  assert.equal(p.calls.downloads.length, 0);
  assert.equal(p.calls.installs.length, 0);
  assert.equal(p.calls.restarts, 0);
});

test("rejects cross-origin, wrong package/AppID and non-canonical update URLs", async () => {
  for (const release of [
    {url: "https://other.example/app-updates/consumer-1.0.3.wgt"},
    {url: "/app-updates/consumer-1.0.3.wgt?redirect=1"},
    {url: "/app-updates/broker-1.0.3.wgt"},
    {package: "top.ourideas.yuzhou.broker"},
    {appid: "__UNI__59A80C3"}
  ]) {
    const p = page({release});
    await p.check(); await p.update();
    assert.equal(p.calls.downloads.length, 0);
    assert.equal(p.calls.installs.length, 0);
  }
});

test("rejects invalid, equal and older resource versions", async () => {
  for (const options of [
    {release: {version_code: "103"}}, {release: {version_code: 102}}, {release: {version_code: 101}},
    {current: {versionCode: "102x"}}, {current: {versionCode: "102.0"}}, {current: {versionCode: null}},
    {current: {versionCode: 0}}, {current: {versionCode: Number.MAX_SAFE_INTEGER + 1}}
  ]) {
    const p = page(options);
    await p.check(); await p.update();
    assert.equal(p.calls.downloads.length, 0);
    assert.equal(p.calls.restarts, 0);
  }
});

test("accepts exact 5.26 runtime family and rejects unverified compiler versions", async () => {
  for (const uniVersion of ["5.26", "5.26.0", "5.26.82680"]) {
    const p = page({uniVersion}); await p.check();
    assert.equal(p.elements.update.textContent, "更新并重新打开");
  }
  for (const options of [{uniVersion: "5.2.6"}, {uniVersion: "5.260"}, {uniVersion: "5.27.0"}, {uniVersion: ""}, {release: {compiler_version: "5.27"}}]) {
    const p = page(options); await p.check(); await p.update();
    assert.equal(p.calls.downloads.length, 0);
  }
});

test("requires bounded byte length, SHA256 and source commit in metadata", async () => {
  for (const release of [{bytes: 0}, {bytes: 100 * 1024 * 1024 + 1}, {bytes: "32"}, {sha256: "abc"}, {source_commit: "main"}]) {
    const p = page({release}); await p.check(); await p.update();
    assert.equal(p.calls.downloads.length, 0);
  }
});

test("network failures show a retry state", async () => {
  for (const options of [{fetchError: true}, {httpError: true}]) {
    const p = page(options); await p.check();
    assert.match(p.elements.status.textContent, /检查网络后重试/);
    assert.equal(p.elements.update.disabled, false);
    assert.equal(p.elements.update.textContent, "重新检查");
  }
});

test("rejects failed downloads, unexpected local paths and unreadable files", async () => {
  for (const options of [
    {downloadCode: 500}, {downloadCode: 0}, {localPath: "https://other.example/update.wgt"},
    {localPath: "_doc/another-app/update.wgt"}, {readError: true}, {readerError: true}
  ]) {
    const p = page(options); await p.check(); await p.update();
    assert.equal(p.calls.installs.length, 0);
    assert.equal(p.calls.restarts, 0);
    assert.equal(p.elements.update.disabled, false);
  }
});

test("verifies both filesystem size and actual bytes plus SHA256 before installation", async () => {
  for (const options of [
    {fileSize: bytes.length + 1}, {fileBytes: Buffer.from("wrong size")},
    {fileBytes: Buffer.alloc(bytes.length, 7)}, {digestError: true}
  ]) {
    const p = page(options); await p.check(); await p.update();
    assert.equal(p.calls.installs.length, 0);
    assert.equal(p.calls.restarts, 0);
    assert.equal(p.elements.update.disabled, false);
    if (options.fileSize !== undefined) assert.equal(p.calls.readers, 0);
  }
});

test("install failure or mismatched installed identity/version never restarts", async () => {
  for (const options of [
    {installError: true}, {installed: {appid: "another-app"}},
    {installed: {version: "1.0.1"}}, {installed: {versionCode: "101"}}
  ]) {
    const p = page(options); await p.check(); await p.update();
    assert.equal(p.calls.installs.length, 1);
    assert.equal(p.calls.installs[0].settings.force, false);
    assert.equal(p.calls.restarts, 0);
    assert.equal(p.elements.update.disabled, false);
  }
});

test("verified WGT installs with force:false then restarts once", async () => {
  const p = page(); await p.check(); await p.update();
  assert.equal(p.calls.downloads.length, 1);
  assert.equal(p.calls.downloads[0].url, "https://yuzhou.ourideas.top/app-updates/consumer-1.0.3.wgt");
  assert.equal(p.calls.downloads[0].settings.filename, "_doc/yuzhou-update/__UNI__E148E73-1.0.3.wgt");
  assert.equal(p.calls.installs.length, 1);
  assert.equal(p.calls.installs[0].settings.force, false);
  assert.equal(p.calls.restarts, 1);
});

test("accepts downloader's own duplicate filename and converted absolute sandbox path", async () => {
  for (const localPath of [
    "_doc/yuzhou-update/__UNI__E148E73-1.0.3(1).wgt",
    "/storage/app/doc/yuzhou-update/__UNI__E148E73-1.0.3.wgt",
    "file:///storage/app/doc/yuzhou-update/__UNI__E148E73-1.0.3.wgt"
  ]) {
    const p = page({localPath}); await p.check(); await p.update();
    assert.equal(p.calls.restarts, 1);
  }
});

test("broker only receives its own WGT", async () => {
  const broker = "__UNI__59A80C3";
  const p = page({appid: broker, release: {appid: broker, package: "top.ourideas.yuzhou.broker", url: "/app-updates/broker-1.0.3.wgt"}});
  await p.check(); await p.update();
  assert.equal(p.calls.downloads[0].url, "https://yuzhou.ourideas.top/app-updates/broker-1.0.3.wgt");
  assert.equal(p.calls.restarts, 1);
});
