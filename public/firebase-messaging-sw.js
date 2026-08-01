importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-auth.js');

firebase.initializeApp({
    apiKey: "AIzaSyBplyJ244NfRt6Iik6OLQhzJsOmtWUOXTk",
    authDomain: "syria-pharmacy-ce885.firebaseapp.com",
    projectId: "syria-pharmacy-ce885",
    storageBucket: "syria-pharmacy-ce885.firebasestorage.app",
    messagingSenderId: "225848042220",
    appId: "1:225848042220:android:305fbf6e97a4b9cb397bc9",
    measurementId: ""
});

const messaging = firebase.messaging();
messaging.setBackgroundMessageHandler(function(payload) {
    return self.registration.showNotification(payload.data.title, {
        body: payload.data.body || '',
        icon: payload.data.icon || ''
    });
});