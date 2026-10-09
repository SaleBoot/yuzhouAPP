const labels = {
  length_m: "总长（米）", waterline_length_m: "水线长（米）",
  width_m: "船宽（米）", beam_m: "船宽（米）", depth_m: "型深（米）",
  draft_m: "吃水（米）", displacement_t: "排水量（吨）",
  power_kw: "主机功率（千瓦）", speed_knots: "航速（节）",
  design_speed: "设计航速", capacity: "核定人数", material: "材质",
  features: "特点", propulsion: "动力形式", navigation_area: "航区",
  certificate_type: "证书类型", fuel_capacity_l: "燃油箱（升）",
  model_code: "船型型号", notes: "备注", "动机形式": "动力形式", "成员定额": "乘员定额",
};
const hidden = new Set(["provider", "external_id", "source", "source_url", "source_note", "来源", "来源说明", "采集说明"]);
const label = key => labels[key] || key;

export function parameterText(value) {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "是" : "否";
  if (typeof value === "number") return Number.isFinite(value) ? String(value) : "—";
  if (Array.isArray(value)) return value.map(parameterText).join("、") || "—";
  if (typeof value === "object")
    return Object.entries(value).filter(([key]) => !hidden.has(key)).map(([key, item]) => `${label(key)}：${parameterText(item)}`).join("；") || "—";
  return String(value);
}

export function boatParameterRows(parameters) {
  if (!parameters || typeof parameters !== "object" || Array.isArray(parameters)) return [];
  return Object.entries(parameters).filter(([key, value]) => {
    if (hidden.has(key)) return false;
    // The imported descriptions repeat these aliases; retain distinct values.
    if (key === "航速说明" && parameters["设计航速"] === value) return false;
    if (key === "载员说明" && ["乘员定额", "额定乘员", "成员定额", "乘员"].some(name => parameters[name] === value)) return false;
    return true;
  }).map(([key, value]) => ({ key: key === "动机形式" ? "动力形式" : key === "成员定额" ? "乘员定额" : key, label: label(key), value: parameterText(value) }));
}

export function comparisonParameterFields(boats) {
  const fields = new Map();
  for (const boat of boats)
    for (const row of boatParameterRows(boat.parameters))
      if (!fields.has(row.key)) fields.set(row.key, { key: row.key, label: row.label });
  return [...fields.values()];
}

export function boatParameterValue(boat, key) {
  return boatParameterRows(boat.parameters).find(row => row.key === key)?.value || "—";
}

export function metricText(value, unit = "", zeroUnknown = false) {
  if (value === null || value === undefined || value === "" || (zeroUnknown && Number(value) <= 0)) return "—";
  return `${parameterText(value)}${unit}`;
}
