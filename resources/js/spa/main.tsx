import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { registerSW } from 'virtual:pwa-register';

import { App } from './App';

registerSW({ immediate: true });

// A shared browser must not keep another account's UI/notification binding in an old tab.
window.addEventListener('storage', (event) => {
    if (event.key === 'helpdesk_user') window.location.reload();
});

const rootElement = document.getElementById('app');
if (!rootElement) {
    throw new Error('Root element #app tidak ditemukan.');
}

createRoot(rootElement).render(
    <StrictMode>
        <App />
    </StrictMode>
);
