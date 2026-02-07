<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import { useTranslation } from '../composables/useTranslation'

const router = useRouter()
const loading = ref(false)
const twoFactorEnabled = ref(false)
const qrCode = ref('')
const secret = ref('')
const recoveryCodes = ref([])
const showRecoveryCodes = ref(false)
const confirmCode = ref('')
const confirmError = ref('')
const showConfirmForm = ref(false)

// Use the global translation system
const { tr, fetchTranslations, currentLang } = useTranslation()

onMounted(async () => {
    // Check if user is authenticated
    const storedUser = localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user');
    if (!storedUser) {
        router.push('/'); // Redirect to home if not authenticated
        return;
    }
    
    // Initialize translations for current language
    try {
        await fetchTranslations(currentLang.value);
    } catch (err) {
        console.error('Failed to initialize translations:', err);
    }
    
    await fetchTwoFactorStatus()
})

async function fetchTwoFactorStatus() {
    try {
        loading.value = true
        const response = await api.get('/self-2fa/status')
        twoFactorEnabled.value = response.data.two_factor_enabled
        
        if (twoFactorEnabled.value) {
            await fetchQrCode()
        }
    } catch (error) {
        if (error.response?.status === 401) {
            // Redirect to login if not authenticated
            router.push('/');
        } else {
            console.error('Error fetching 2FA status:', error)
        }
    } finally {
        loading.value = false
    }
}

async function fetchQrCode() {
    try {
        const response = await api.get('/self-2fa/qr')
        qrCode.value = response.data.svg
        secret.value = response.data.secret
    } catch (error) {
        console.error('Error fetching QR code:', error)
    }
}

async function enableTwoFactor() {
    try {
        loading.value = true
        await api.post('/self-2fa/enable')
        twoFactorEnabled.value = true
        await fetchQrCode()
        showConfirmForm.value = true
        confirmError.value = ''
    } catch (error) {
        if (error.response?.status === 401) {
            // Redirect to login if not authenticated
            router.push('/');
        } else {
            console.error('Error enabling 2FA:', error)
        }
    } finally {
        loading.value = false
    }
}

async function disableTwoFactor() {
    try {
        loading.value = true
        await api.delete('/self-2fa/disable')
        twoFactorEnabled.value = false
        qrCode.value = ''
        secret.value = ''
        recoveryCodes.value = []
        showRecoveryCodes.value = false
        confirmCode.value = ''
        confirmError.value = ''
        showConfirmForm.value = false
    } catch (error) {
        if (error.response?.status === 401) {
            // Redirect to login if not authenticated
            router.push('/');
        } else {
            console.error('Error disabling 2FA:', error)
        }
    } finally {
        loading.value = false
    }
}

async function fetchRecoveryCodes() {
    try {
        loading.value = true
        const response = await api.get('/self-2fa/recovery')
        recoveryCodes.value = response.data
        showRecoveryCodes.value = true
    } catch (error) {
        if (error.response?.status === 401) {
            // Redirect to login if not authenticated
            router.push('/');
        } else {
            console.error('Error fetching recovery codes:', error)
        }
    } finally {
        loading.value = false
    }
}

async function confirmTwoFactor() {
    try {
        loading.value = true
        await api.post('/self-2fa/confirm', { code: confirmCode.value })
        showConfirmForm.value = false
        confirmCode.value = ''
        confirmError.value = ''
        await fetchTwoFactorStatus()
    } catch (error) {
        if (error.response?.status === 401) {
            // Redirect to login if not authenticated
            router.push('/');
        } else {
            confirmError.value = 'Ungültiger Code. Bitte versuchen Sie es erneut.'
            console.error('Error confirming 2FA:', error)
        }
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <div class="card">
        <h2>{{ tr('two_factor_auth', 'Two-Factor Authentication') }}</h2>
        
        <div v-if="loading" class="loading">{{ tr('loading', 'Loading...') }}</div>
        
        <div v-else>
            <div v-if="!twoFactorEnabled">
                <p>{{ tr('enable_2fa_description', 'Enable Two-Factor Authentication to add an extra layer of security to your account.') }}</p>
                <button @click="enableTwoFactor" class="btn-primary">{{ tr('enable_2fa', 'Enable 2FA') }}</button>
            </div>
            
            <div v-else>
                <p>{{ tr('2fa_enabled_description', 'Two-Factor Authentication is enabled.') }}</p>
                <button @click="disableTwoFactor" class="btn-danger">{{ tr('disable_2fa', 'Disable 2FA') }}</button>
                
                <!-- QR Code Display -->
                <div v-if="qrCode" class="qr-code-container">
                    <h3>{{ tr('qr_code_title', 'Scan this QR code') }}</h3>
                    <p>{{ tr('qr_code_description', 'Scan the QR code with your authenticator app to set up 2FA.') }}</p>
                    <div class="qr-code" v-html="qrCode"></div>
                    <div class="secret-container">
                        <h3>{{ tr('totp_secret', 'TOTP Secret') }}</h3>
                        <p>{{ tr('secret_description', 'If you cannot scan the QR code, you can enter this secret manually in your authenticator app:') }}</p>
                        <div class="secret-display">
                            <span class="secret">{{ secret }}</span>
                        </div>
                    </div>
                </div>
                
                <div v-if="showRecoveryCodes">
                    <h3>{{ tr('recovery_codes', 'Recovery Codes') }}</h3>
                    <p>{{ tr('recovery_codes_description', 'Save these codes in a secure location. They can be used to access your account if you lose access to your 2FA app.') }}</p>
                    <div class="recovery-codes">
                        <span v-for="(code, index) in recoveryCodes" :key="index" class="recovery-code">{{ code }}</span>
                    </div>
                </div>
                
                <button v-if="!showRecoveryCodes" @click="fetchRecoveryCodes" class="btn-secondary">{{ tr('show_recovery_codes', 'Show Recovery Codes') }}</button>
            </div>
            
            <div v-if="showConfirmForm" class="confirm-form">
                <h3>{{ tr('2fa_confirmation', '2FA Confirmation') }}</h3>
                <p>{{ tr('enter_otp_code', 'Enter the 6-digit code from your authentication app:') }}</p>
                <input
                    v-model="confirmCode"
                    type="text"
                    :placeholder="tr('otp_placeholder', '123456')"
                    maxlength="6"
                    class="input-lg"
                />
                <div v-if="confirmError" class="error">{{ confirmError }}</div>
                <button @click="confirmTwoFactor" class="btn-primary">{{ tr('confirm', 'Confirm') }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.card {
    background: var(--app-card-bg, rgba(255,255,255,0.9));
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 1.25rem;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    margin: 1rem 0;
}

.btn-primary {
    background: #2563eb;
    color: white;
    border: none;
    padding: 0.75rem 1.25rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    margin: 0.5rem 0;
}

.btn-danger {
    background: #dc2626;
    color: white;
    border: none;
    padding: 0.75rem 1.25rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    margin: 0.5rem 0;
}

.btn-secondary {
    background: #64748b;
    color: white;
    border: none;
    padding: 0.75rem 1.25rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    margin: 0.5rem 0;
}

.loading {
    text-align: center;
    padding: 1rem;
}

.confirm-form {
    margin-top: 1rem;
    padding: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}

.recovery-codes {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin: 1rem 0;
}

.recovery-code {
    background: #f1f5f9;
    padding: 0.5rem;
    border-radius: 4px;
    font-family: monospace;
}

.qr-code-container {
    margin-top: 1rem;
    padding: 1rem;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
}

.qr-code {
    text-align: center;
    margin: 1rem 0;
}

.secret-container {
    margin-top: 1rem;
    padding: 1rem;
    background: var(--surface-muted);
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    color: var(--text);
}

.secret-display {
    text-align: center;
    margin: 0.5rem 0;
}

.secret {
    font-family: monospace;
    font-size: 1.2rem;
    padding: 0.5rem;
    background: var(--surface);
    border-radius: 4px;
    word-break: break-all;
    color: var(--text);
}
</style>