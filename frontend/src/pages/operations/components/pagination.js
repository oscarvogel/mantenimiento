export function pageSizeUrl(pagination, size, href = window.location.href) {
  const allowed = pagination.perPageOptions || [5, 10, 25]
  const parsed = Number(size)
  const selected = allowed.includes(parsed) ? parsed : 10
  const url = new URL(href)

  url.searchParams.set(pagination.perPageKey || 'per_page', String(selected))
  url.searchParams.set(pagination.pageKey || 'page', '1')

  return url.toString()
}
