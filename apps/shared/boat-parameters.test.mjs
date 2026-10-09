import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { parameterText, boatParameterRows, comparisonParameterFields, boatParameterValue, metricText } from "./boat-parameters.js";
const boats = JSON.parse(readFileSync(new URL("../../data/test-catalog/catalog.json", import.meta.url), "utf8")).boats;

test("actual design speed retains qualifiers and original units across the 19 boats", () => {
  assert.equal(boats.length, 19);
  const patrol = boats.find(boat => boat.external_id === "2138");
  const speed = boatParameterRows(patrol.parameters).find(row => row.key === "设计航速");
  assert.equal(speed.label, "设计航速");
  assert.equal(speed.value, "约65km/h（35kn），非实船测速值");
  assert.equal(boatParameterValue(boats.find(boat => boat.external_id === "2"), "设计航速"), "≥50km/h");
  assert.ok(boats.every(boat => !Object.hasOwn(boat, "speed_knots")));
  assert.ok(boatParameterRows(patrol.parameters).find(row => row.key === "备注").value.includes("本艇专属船检证书待提供"));
});
test("structured parameters render readable lists, names and values without JSON", () => {
  assert.equal(parameterText(["快速响应", "救援甲板"]), "快速响应、救援甲板");
  assert.equal(parameterText({ power_kw: 22, material: "铝合金", "可选": true, "标配": false, "数量": 0, provider: "hidden" }), "主机功率（千瓦）：22；材质：铝合金；可选：是；标配：否；数量：0");
  assert.equal(parameterText([]), "—");
  assert.equal(parameterText(null), "—");
});
test("only matching aliases are deduplicated and source metadata is excluded", () => {
  const rows = boatParameterRows({ "设计航速": "≥50km/h", "航速说明": "≥50km/h", "乘员定额": "12人(含船员)", "载员说明": "12人(含船员)", "动机形式": "舷外挂机", provider: "hidden", external_id: 2, "采集说明": "hidden" });
  assert.deepEqual(rows.map(row => row.label), ["设计航速", "乘员定额", "动力形式"]);
  assert.equal(boatParameterRows({ "设计航速": "约50km/h", "航速说明": "实测未提供" }).length, 2);
});
test("comparison shows the selected boats' union of parameter keys with honest unknowns", () => {
  const patrol = boats.find(boat => boat.external_id === "2138"), rescue = boats.find(boat => boat.external_id === "7");
  const fields = comparisonParameterFields([patrol, rescue]);
  assert.ok(fields.some(field => field.key === "燃油箱"));
  assert.ok(fields.some(field => field.key === "特点"));
  assert.equal(boatParameterValue(rescue, "燃油箱"), "—");
  assert.equal(boatParameterValue(rescue, "特点"), "快速响应、救援甲板、浅水适应、便捷运输");
  assert.equal(metricText(rescue.capacity, "人", true), "—");
  assert.equal(metricText(null, "节"), "—");
  assert.equal(metricText("5.40", "m"), "5.40m");
  assert.equal(comparisonParameterFields([boats.find(boat => boat.external_id === "1"), boats.find(boat => boat.external_id === "4")]).filter(field => field.key === "动力形式").length, 1);
});
