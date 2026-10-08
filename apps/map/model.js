export function locatedBoats(items) {
  return items.filter((boat) => {
    const p = boat.payload || {};
    return boat.kind === "boats" && boat.status === "published" &&
      Number.isSafeInteger(Number(boat.id)) && Number(boat.id) > 0 &&
      Number.isSafeInteger(Number(p.boat_type_id)) && Number(p.boat_type_id) > 0 &&
      typeof p.latitude === "number" && Number.isFinite(p.latitude) && Math.abs(p.latitude) <= 90 &&
      typeof p.longitude === "number" && Number.isFinite(p.longitude) && Math.abs(p.longitude) <= 180;
  });
}
export function boatPath(client, boat) {
  return client === "broker"
    ? `/pages/quote?boat_id=${encodeURIComponent(boat.id)}`
    : `/pages/boat?id=${encodeURIComponent(boat.payload.boat_type_id)}&instance_id=${encodeURIComponent(boat.id)}&mode=buy`;
}
export function validDetailMessage(data) {
  return data?.action === "boat-detail" &&
    Number.isSafeInteger(data.boat_id) && data.boat_id > 0 &&
    Number.isSafeInteger(data.boat_type_id) && data.boat_type_id > 0;
}
