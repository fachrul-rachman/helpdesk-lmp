import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { registerSW } from 'virtual:pwa-register';

import { App } from './App';

registerSW({ immediate: true });

const rootElement = document.getElementById('app');
if (!rootElement) {
    throw new Error('Root element #app tidak ditemukan.');
}

createRoot(rootElement).render(
    <StrictMode>
        <App />
    </StrictMode>
);
