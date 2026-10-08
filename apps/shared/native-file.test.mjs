import assert from "node:assert/strict";
import test from "node:test";
import { choosePdf } from "./native-file.js";

function androidPicker(reads, launchError) {
  const state = { inputClosed: false, outputClosed: false, deleted: false, writes: [], forwarded: 0 };
  const input = { read: () => reads.shift(), close: () => { state.inputClosed = true; } };
  const previous = () => { state.forwarded++; };
  const activity = {
    onActivityResult: previous,
    getContentResolver: () => ({ openInputStream: () => input }),
    startActivityForResult: () => { if (launchError) throw new Error("picker unavailable"); },
  };
  class Intent {
    static ACTION_OPEN_DOCUMENT = "open";
    static CATEGORY_OPENABLE = "openable";
    setType() {}
    addCategory() {}
  }
  class FileOutputStream {
    write(bytes, start, count) { state.writes.push(count); }
    close() { state.outputClosed = true; }
  }
  class File { delete() { state.deleted = true; } }
  const classes = {
    "android.content.Intent": Intent,
    "java.io.FileOutputStream": FileOutputStream,
    "java.lang.reflect.Array": { newInstance: () => new Uint8Array(8192) },
    "java.lang.Byte": { TYPE: "byte" },
    "java.io.File": File,
  };
  globalThis.plus = {
    os: { name: "Android" },
    android: {
      runtimeMainActivity: () => activity,
      importClass: (value) => typeof value === "string" ? classes[value] : value,
    },
    io: { convertLocalFileSystemURL: (path) => `/private/${path}` },
  };
  const select = () => activity.onActivityResult(45201, -1, { getData: () => "content://pdf" });
  return { state, activity, previous, select };
}

test("PDF selection copies nonempty data, closes streams and restores callbacks", async () => {
  const picker = androidPicker([3, -1]);
  const result = choosePdf();
  picker.activity.onActivityResult(123, 0, null);
  assert.equal(picker.state.forwarded, 1);
  picker.select();
  assert.match(await result, /^\/private\/_doc\/contract-\d+\.pdf$/);
  assert.deepEqual(picker.state.writes, [3]);
  assert.equal(picker.state.inputClosed, true);
  assert.equal(picker.state.outputClosed, true);
  assert.equal(picker.state.deleted, false);
  assert.equal(picker.activity.onActivityResult, picker.previous);
});

test("silent NativeJS read failure rejects instead of looping and removes partial copies", async () => {
  const picker = androidPicker([3, undefined]);
  const result = choosePdf();
  picker.select();
  await assert.rejects(result, /读取结果/);
  assert.equal(picker.state.inputClosed, true);
  assert.equal(picker.state.outputClosed, true);
  assert.equal(picker.state.deleted, true);
});

test("empty PDFs are rejected", async () => {
  const picker = androidPicker([-1]);
  const result = choosePdf();
  picker.select();
  await assert.rejects(result, /PDF为空/);
  assert.equal(picker.state.deleted, true);
});

test("unavailable Android picker restores callback and reports failure", async () => {
  const picker = androidPicker([], true);
  await assert.rejects(choosePdf(), /无法打开PDF选择器/);
  assert.equal(picker.activity.onActivityResult, picker.previous);
});

test("cancelling PDF selection restores callback without copying", async () => {
  const picker = androidPicker([]);
  const result = choosePdf();
  picker.activity.onActivityResult(45201, 0, null);
  await assert.rejects(result, /已取消/);
  assert.equal(picker.activity.onActivityResult, picker.previous);
  assert.equal(picker.state.outputClosed, false);
});
