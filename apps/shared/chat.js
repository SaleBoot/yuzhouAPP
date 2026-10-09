export async function conversationMessages(request, id, existing, current) {
  const messages = [...existing];
  let after = Number(messages[messages.length - 1]?.id || 0);
  while (current()) {
    const page = await request(`/conversations/${id}/messages?after_id=${after}&page_size=100`);
    if (!current()) return null;
    const items = page.items || [];
    if (!items.length) return messages;
    for (const item of items) {
      const next = Number(item.id);
      if (!Number.isSafeInteger(next) || next <= after) throw new Error("消息分页异常，请重试");
      after = next;
      messages.push(item);
    }
    if (items.length >= Number(page.total)) return messages;
  }
  return null;
}
