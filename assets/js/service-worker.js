/**
 * SmartSchool Hub — Service Worker pour les notifications Push
 */

const CACHE_NAME = 'smartschool-v1.0.0';
const OFFLINE_URL = '/offline.html';

// Liste des ressources à mettre en cache
const CACHE_ASSETS = [
  '/',
  '/index.php',
  '/login.php',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/images/icon-192x192.png',
  '/assets/images/badge-72x72.png',
  '/offline.html'
];

// Installation du service worker
self.addEventListener('install', (event) => {
  console.log('Service Worker: Installation...');
  
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('Service Worker: Mise en cache des ressources');
        return cache.addAll(CACHE_ASSETS);
      })
      .then(() => {
        console.log('Service Worker: Installation terminée');
        return self.skipWaiting();
      })
  );
});

// Activation du service worker
self.addEventListener('activate', (event) => {
  console.log('Service Worker: Activation...');
  
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            console.log('Service Worker: Suppression de l\'ancien cache', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
    .then(() => {
      console.log('Service Worker: Activation terminée');
      return self.clients.claim();
    })
  );
});

// Gestion des requêtes (offline-first)
self.addEventListener('fetch', (event) => {
  // Ne pas intercepter les requêtes API
  if (event.request.url.includes('/api/')) {
    return;
  }
  
  event.respondWith(
    caches.match(event.request)
      .then((response) => {
        // Retourner depuis le cache si disponible
        if (response) {
          return response;
        }
        
        // Sinon, faire la requête réseau
        return fetch(event.request)
          .then((response) => {
            // Vérifier si la réponse est valide
            if (!response || response.status !== 200 || response.type !== 'basic') {
              return response;
            }
            
            // Mettre en cache la nouvelle réponse
            const responseToCache = response.clone();
            caches.open(CACHE_NAME)
              .then((cache) => {
                cache.put(event.request, responseToCache);
              });
            
            return response;
          })
          .catch(() => {
            // Si la requête échoue et c'est une requête de page, retourner la page offline
            if (event.request.destination === 'document') {
              return caches.match(OFFLINE_URL);
            }
          });
      })
  );
});

// Gestion des notifications Push
self.addEventListener('push', (event) => {
  console.log('Service Worker: Notification Push reçue');
  
  let notificationData = {
    title: 'SmartSchool Hub',
    body: 'Vous avez une nouvelle notification',
    icon: '/assets/images/icon-192x192.png',
    badge: '/assets/images/badge-72x72.png',
    tag: 'general',
    requireInteraction: false,
    data: {
      url: '/',
      timestamp: Date.now()
    }
  };
  
  // Parser les données de la notification si présentes
  if (event.data) {
    try {
      notificationData = { ...notificationData, ...event.data.json() };
    } catch (e) {
      console.error('Erreur parsing notification data:', e);
    }
  }
  
  event.waitUntil(
    self.registration.showNotification(notificationData.title, {
      body: notificationData.body,
      icon: notificationData.icon,
      badge: notificationData.badge,
      tag: notificationData.tag,
      requireInteraction: notificationData.requireInteraction,
      data: notificationData.data,
      actions: notificationData.actions || [],
      silent: false
    })
  );
});

// Gestion du clic sur notification
self.addEventListener('notificationclick', (event) => {
  console.log('Service Worker: Clic sur notification');
  
  event.notification.close();
  
  // Déterminer l'URL à ouvrir
  let urlToOpen = '/';
  
  if (event.notification.data && event.notification.data.url) {
    urlToOpen = event.notification.data.url;
  }
  
  // Action personnalisée si définie
  if (event.action) {
    switch (event.action) {
      case 'view':
        urlToOpen = event.notification.data.url || '/';
        break;
      case 'dismiss':
        // Juste fermer la notification
        return;
      default:
        urlToOpen = '/';
    }
  }
  
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((clientList) => {
        // Chercher un client existant avec la même URL
        for (const client of clientList) {
          if (client.url === urlToOpen && 'focus' in client) {
            return client.focus();
          }
        }
        
        // Ouvrir une nouvelle fenêtre si aucun client trouvé
        if (clients.openWindow) {
          return clients.openWindow(urlToOpen);
        }
      })
  );
});

// Gestion de la fermeture de notification
self.addEventListener('notificationclose', (event) => {
  console.log('Service Worker: Notification fermée');
  
  // Envoyer une statistique de fermeture (optionnel)
  if (event.notification.data && event.notification.data.notification_id) {
    fetch('/api/notifications/dismiss', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        notification_id: event.notification.data.notification_id
      })
    }).catch(err => {
      console.error('Erreur envoi statistique fermeture:', err);
    });
  }
});

// Synchronisation en arrière-plan (si supporté)
self.addEventListener('sync', (event) => {
  console.log('Service Worker: Événement de synchronisation', event.tag);
  
  if (event.tag === 'background-sync') {
    event.waitUntil(doBackgroundSync());
  }
});

// Fonction de synchronisation en arrière-plan
async function doBackgroundSync() {
  try {
    // Récupérer les notifications en attente depuis IndexedDB
    const pendingNotifications = await getPendingNotifications();
    
    // Envoyer les notifications en attente
    for (const notification of pendingNotifications) {
      try {
        await sendNotification(notification);
        await removePendingNotification(notification.id);
      } catch (error) {
        console.error('Erreur envoi notification en attente:', error);
      }
    }
  } catch (error) {
    console.error('Erreur synchronisation arrière-plan:', error);
  }
}

// Fonctions utilitaires pour IndexedDB (simplifié)
function getPendingNotifications() {
  return new Promise((resolve, reject) => {
    // Implémentation avec IndexedDB pour stocker les notifications en attente
    resolve([]);
  });
}

function removePendingNotification(id) {
  return new Promise((resolve, reject) => {
    // Implémentation IndexedDB
    resolve();
  });
}

function sendNotification(notification) {
  return fetch('/api/notifications/send', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(notification)
  });
}

// Gestion des messages du client (pour communication bidirectionnelle)
self.addEventListener('message', (event) => {
  console.log('Service Worker: Message reçu du client', event.data);
  
  if (event.data && event.data.type === 'GET_VERSION') {
    event.ports[0].postMessage({
      type: 'VERSION',
      version: CACHE_NAME
    });
  }
  
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// Nettoyage du cache
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'DELETE_CACHE') {
    caches.delete(CACHE_NAME);
  }
});

console.log('Service Worker: Chargé avec succès');
