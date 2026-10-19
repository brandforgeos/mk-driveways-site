// Build Bro Hub — phone notifications (loaded into the app's service worker)
self.addEventListener('push', (event) => {
  let d = {}
  try { d = event.data ? event.data.json() : {} } catch { d = { title: 'Build Bro Hub', body: event.data ? event.data.text() : '' } }
  event.waitUntil(self.registration.showNotification(d.title || 'Build Bro Hub', {
    body: d.body || '', tag: d.tag, data: { url: d.url || '/' }, icon: '/icon-192.png', badge: '/icon-192.png', renotify: !!d.tag,
  }))
})
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    for (const w of wins) {
      if (new URL(w.url).origin === self.location.origin) { await w.focus(); try { await w.navigate(url) } catch { w.postMessage({ type: 'open', url }) } return }
    }
    await self.clients.openWindow(url)
  })())
})
// deploy 1792411254
