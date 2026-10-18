let csrfToken = null
async function csrf() {
  if (!csrfToken) {
    const response = await fetch('/api/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error('Không thể tạo phiên bảo mật. Vui lòng tải lại trang.')
    csrfToken = (await response.json()).token
  }
  return csrfToken
}
export async function api(path, options = {}) {
  const method = options.method || 'GET'
  const headers = { Accept: 'application/json', ...options.headers }
  if (!['GET', 'HEAD'].includes(method)) headers['X-CSRF-TOKEN'] = await csrf()
  let body = options.body
  if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body) }
  let response
  try { response = await fetch('/api' + path, { ...options, credentials: 'same-origin', body, headers }) }
  catch { throw new Error('Không thể kết nối máy chủ. Câu trả lời vẫn được giữ trên thiết bị; hãy thử lưu lại.') }
  const result = (response.headers.get('content-type') || '').includes('application/json') ? await response.json() : null
  if (!response.ok) {
    if (response.status === 419) csrfToken = null
    const firstError = result?.errors && Object.values(result.errors)[0]?.[0]
    const error = new Error(firstError || result?.message || 'Thao tác chưa thành công. Vui lòng thử lại.')
    error.status = response.status
    throw error
  }
  if (path === '/login' || path === '/logout' || path === '/account/password') csrfToken = null
  return result
}
export async function downloadFile(file) {
  const response = await fetch('/api/files/' + file.id, { credentials: 'same-origin' })
  if (!response.ok) throw new Error('Không thể tải tệp. Vui lòng thử lại.')
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a'); link.href = url; link.download = file.name; link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
