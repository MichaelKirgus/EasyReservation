import { createRouter, createWebHistory } from 'vue-router'
import PublicReservation from '../components/PublicReservation.vue'
import PublicPrivacy from '../components/PublicPrivacy.vue'
import PublicFaq from '../components/PublicFaq.vue'
import AdminReservations from '../components/AdminReservations.vue'
import AdminEmailBroadcast from '../components/AdminEmailBroadcast.vue'
import AdminFaq from '../components/AdminFaq.vue'
import AdminEvents from '../components/AdminEvents.vue'
import AdminDiagnostics from '../components/AdminDiagnostics.vue'
import AdminSettings from '../components/AdminSettings.vue'
import AdminScheduledTasks from '../components/AdminScheduledTasks.vue'
import FormFieldManager from '../components/FormFieldManager.vue'
import AdminUsers from '../components/AdminUsers.vue'
import AdminCustomPlaceholders from '../components/AdminCustomPlaceholders.vue'
import AdminArchives from '../components/AdminArchives.vue'
import AdminPlaceholderValues from '../components/AdminPlaceholderValues.vue'
import AdminSurveys from '../components/AdminSurveys.vue'
import SurveyQuestionManager from '../components/SurveyQuestionManager.vue'
import AdminAuditLog from '../components/AdminAuditLog.vue'
import TwoFactorSettings from '../components/TwoFactorSettings.vue'
import PublicSurvey from '../components/PublicSurvey.vue'
import AdminMailTransports from '../components/AdminMailTransports.vue'

const routes = [
  { path: '/', name: 'reservation', component: PublicReservation },
  { path: '/privacy', name: 'privacy', component: PublicPrivacy },
  { path: '/faq', name: 'faq', component: PublicFaq },
  // Moderation
  { path: '/moderation/reservations', name: 'moderation-reservations', component: AdminReservations },
  { path: '/moderation/email', name: 'moderation-email', component: AdminEmailBroadcast },
  { path: '/moderation/faq', name: 'moderation-faq', component: AdminFaq },
  { path: '/moderation/events', name: 'moderation-events', component: AdminEvents },
  { path: '/moderation/surveys', name: 'moderation-surveys', component: AdminSurveys },
  { path: '/moderation/placeholders', name: 'moderation-placeholders', component: AdminPlaceholderValues },
  // Administration
  { path: '/admin/diagnostics', name: 'admin-diagnostics', component: AdminDiagnostics },
  { path: '/admin/settings', name: 'admin-settings', component: AdminSettings },
  { path: '/admin/scheduled-tasks', name: 'admin-scheduled-tasks', component: AdminScheduledTasks },
  { path: '/admin/form-fields', name: 'admin-form-fields', component: FormFieldManager },
  { path: '/admin/users', name: 'admin-users', component: AdminUsers },
  { path: '/admin/archives', name: 'admin-archives', component: AdminArchives },
  { path: '/admin/custom-placeholders', name: 'admin-custom-placeholders', component: AdminCustomPlaceholders },
  { path: '/admin/surveys', name: 'admin-surveys', component: AdminSurveys },
  { path: '/admin/mail-transports', name: 'admin-mail-transports', component: AdminMailTransports },
  { path: '/moderation/surveys/:id/questions', name: 'survey-questions', component: SurveyQuestionManager, props: true },
  { path: '/admin/auditlog', name: 'superadmin-auditlog', component: AdminAuditLog },
  // User settings
  { path: '/user/2fa', name: 'user-2fa', component: TwoFactorSettings },
  // Public survey (accessible via link)
  { path: '/survey/:id', name: 'public-survey', component: PublicSurvey, props: true },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export default router
