// Helper to display a job type without namespace
export function jobTypeLabel(type) {
  // Remove everything up to and including the last backslash
  return type.replace(/^.*\\/, '')
}
