export function getCurrentUser() {
  const meta = document.querySelector('meta[name="laravel-user"]');
  if (!meta) return null;

  try {
    return JSON.parse(meta.getAttribute('content') || 'null');
  } catch {
    return null;
  }
}

export function getCurrentPermissions(): string[] {
  const meta = document.querySelector('meta[name="laravel-permissions"]');
  if (!meta) return [];

  try {
    const parsed = JSON.parse(meta.getAttribute('content') || '[]');
    return Array.isArray(parsed) ? parsed.map(String) : [];
  } catch {
    return [];
  }
}

export function hasPermission(permission: string) {
  return getCurrentPermissions().includes(permission);
}

export function hasAnyPermission(permissions?: string[]) {
  if (!permissions || permissions.length === 0) return true;
  const current = new Set(getCurrentPermissions());
  return permissions.some((permission) => current.has(permission));
}
