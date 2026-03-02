import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [vue()],
    define: {
        __APP_VERSION__: JSON.stringify(process.env.APP_VERSION || 'unknown'),
    },
    server: {
        proxy: {
            '/api': {
                target: process.env.VITE_API_PROXY_TARGET || 'http://localhost:8000',
                changeOrigin: true,
            },
            '/storage': {
                target: process.env.VITE_API_PROXY_TARGET || 'http://localhost:8000',
                changeOrigin: true,
            },
        },
    },
})
