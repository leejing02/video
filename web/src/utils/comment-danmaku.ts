/** Keep each video overlay bounded; comment text is rendered as text, never HTML. */
export function commentDanmaku(comments: { id: number; content: string }[]) {
  const seen = new Set<number>()
  return comments.flatMap(comment => {
    const text = comment.content.trim()
    if (!text || seen.has(comment.id)) return []
    seen.add(comment.id)
    return [{ id: comment.id, text: Array.from(text).slice(0, 80).join('') }]
  }).slice(0, 12)
}
