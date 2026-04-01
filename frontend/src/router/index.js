import { createRouter, createWebHistory } from 'vue-router'
import PublicReservation from '../components/PublicReservation.vue'
import PublicPrivacy from '../components/PublicPrivacy.vue'
import PublicFaq from '../components/PublicFaq.vue'
import AdminReservations from '../components/AdminReservations.vue'
import TemplateManager from '../components/templates/TemplateManager.vue'
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
import ModerationDashboard from '../components/ModerationDashboard.vue'
import SurveyQuestionManager from '../components/SurveyQuestionManager.vue'
import AdminAuditLog from '../components/AdminAuditLog.vue'
import TwoFactorSettings from '../components/TwoFactorSettings.vue'
import PublicSurvey from '../components/PublicSurvey.vue'
import AdminMailTransports from '../components/AdminMailTransports.vue'
import AdminValidationRules from '../components/AdminValidationRules.vue'
import AdminDataPortability from '../components/AdminDataPortability.vue'

const ROLES_MODERATION = ['moderator', 'admin', 'superadmin']
const ROLES_ADMIN = ['admin', 'superadmin']
const ROLES_SUPERADMIN = ['superadmin']
const ROLES_AUTHENTICATED = ['user', 'moderator', 'admin', 'superadmin']

const routes = [
  { path: '/survey', name: 'public-survey-query', component: PublicSurvey, props: route => ({ id: route.query.survey_id }) },
  { path: '/', name: 'reservation', component: PublicReservation },
  { path: '/privacy', name: 'privacy', component: PublicPrivacy },
  { path: '/faq', name: 'faq', component: PublicFaq },
  // Moderation
  { path: '/moderation/reservations', name: 'moderation-reservations', component: AdminReservations, meta: { roles: ROLES_MODERATION } },
  {
    path: '/moderation/templates',
    name: 'moderation-templates',
    component: TemplateManager,
    meta: { roles: ROLES_MODERATION },
    children: [
      { path: '', redirect: { name: 'template-email-list' } },
      { path: 'email/send', name: 'template-email-send', component: () => import('../components/templates/EmailBroadcastManager.vue') },
      { path: 'email/list', name: 'template-email-list', component: () => import('../components/templates/EmailTemplateList.vue') },
      { path: 'ical/list', name: 'template-ical-list', component: () => import('../components/templates/IcalTemplateList.vue') },
      { path: 'attachment/list', name: 'template-attachment-list', component: () => import('../components/templates/AttachmentTemplateList.vue') },
    ]
  },
  { path: '/moderation/faq', name: 'moderation-faq', component: AdminFaq, meta: { roles: ROLES_MODERATION } },
  { path: '/moderation/events', name: 'moderation-events', component: AdminEvents, meta: { roles: ROLES_MODERATION } },
  { path: '/moderation/dashboard', name: 'moderation-dashboard', component: ModerationDashboard, meta: { roles: ROLES_MODERATION } },
  { path: '/moderation/surveys', name: 'moderation-surveys', component: AdminSurveys, meta: { roles: ROLES_MODERATION } },
  { path: '/moderation/placeholders', name: 'moderation-placeholders', component: AdminPlaceholderValues, meta: { roles: ROLES_MODERATION } },
  // Administration
  { path: '/admin/diagnostics', name: 'admin-diagnostics', component: AdminDiagnostics, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/settings', name: 'admin-settings', component: AdminSettings, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/scheduled-tasks', name: 'admin-scheduled-tasks', component: AdminScheduledTasks, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/form-fields', name: 'admin-form-fields', component: FormFieldManager, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/users', name: 'admin-users', component: AdminUsers, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/archives', name: 'admin-archives', component: AdminArchives, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/custom-placeholders', name: 'admin-custom-placeholders', component: AdminCustomPlaceholders, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/surveys', name: 'admin-surveys', component: AdminSurveys, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/mail-transports', name: 'admin-mail-transports', component: AdminMailTransports, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/data-portability', name: 'admin-data-portability', component: AdminDataPortability, meta: { roles: ROLES_ADMIN } },
  { path: '/admin/validation-rules', name: 'admin-validation-rules', component: AdminValidationRules, meta: { roles: ROLES_ADMIN } },
  { path: '/moderation/surveys/:id/questions', name: 'survey-questions', component: SurveyQuestionManager, props: true, meta: { roles: ROLES_MODERATION } },
  { path: '/admin/auditlog', name: 'superadmin-auditlog', component: AdminAuditLog, meta: { roles: ROLES_SUPERADMIN } },
  // User settings
  { path: '/user/2fa', name: 'user-2fa', component: TwoFactorSettings, meta: { roles: ROLES_AUTHENTICATED } },
  // Public survey (accessible via link)
  { path: '/surveys/:id', name: 'public-survey', component: PublicSurvey, props: true },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

function normalizeRole(role) {
  return typeof role === 'string' ? role.toLowerCase().trim() : ''
}

function hasSessionMarker() {
  return !!(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session'))
}

function getStoredUser() {
  const raw = localStorage.getItem('admin_user') || sessionStorage.getItem('admin_user')
  if (!raw) return null
  try {
    return JSON.parse(raw)
  } catch (_) {
    return null
  }
}

router.beforeEach((to, _from, next) => {
  const requiredRoles = to.meta?.roles
  if (!Array.isArray(requiredRoles) || requiredRoles.length === 0) {
    next()
    return
  }

  if (!hasSessionMarker()) {
    next({ path: '/' })
    return
  }

  const storedUser = getStoredUser()
  const currentRole = normalizeRole(storedUser?.role)
  if (currentRole && requiredRoles.includes(currentRole)) {
    next()
    return
  }

  next({ path: '/' })
})

export default router
