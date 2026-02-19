export function getCurrentUser() {
  const meta = document.querySelector('meta[name="laravel-user"]');
  if (!meta) return null;

  try {
    return JSON.parse(meta.getAttribute('content') || 'null');
  } catch {
    return null;
  }
}
