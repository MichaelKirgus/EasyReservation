import { marked } from 'marked'
import DOMPurify from 'dompurify'

// Standard markdown renderer used across public-facing pages
marked.setOptions({ gfm: true, breaks: true })

export function renderMarkdown(raw) {
  if (!raw) return ''
  const html = marked.parse(String(raw))
  return DOMPurify.sanitize(html)
}
