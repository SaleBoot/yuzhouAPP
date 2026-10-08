import test from "node:test";
import assert from "node:assert/strict";
import { locatedBoats, boatPath, validDetailMessage } from "./model.js";
const boat = { id: 5, kind: "boats", status: "published", payload: { boat_type_id: 2, latitude: 0, longitude: 0 } };
test("only published actual boats with paired valid coordinates enter the map", () => {
  const items = [boat,
    { ...boat, status: "pending" },
    { ...boat, kind: "boat_types" },
    { ...boat, payload: { ...boat.payload, latitude: null } },
    { ...boat, payload: { ...boat.payload, longitude: "121.5" } },
    { ...boat, payload: { ...boat.payload, latitude: 91 } },
    { ...boat, payload: { ...boat.payload, longitude: Infinity } },
  ];
  assert.deepEqual(locatedBoats(items), [boat]);
});
test("detail paths carry the actual instance and validate bridge identifiers", () => {
  assert.equal(boatPath("consumer", boat), "/pages/boat?id=2&instance_id=5&mode=buy");
  assert.equal(boatPath("broker", boat), "/pages/quote?boat_id=5");
  assert.equal(validDetailMessage({ action: "boat-detail", boat_id: 5, boat_type_id: 2 }), true);
  assert.equal(validDetailMessage({ action: "boat-detail", boat_id: "5", boat_type_id: 2 }), false);
  assert.equal(validDetailMessage({ action: "boat-detail", boat_id: -1, boat_type_id: 2 }), false);
});
