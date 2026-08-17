/**
 * English message catalog — the source of truth for message keys.
 *
 * Phase 22 (National Scale) localization: the interface is English-first
 * with a full Nepali (Devanagari) counterpart (`ne.ts`). The catalog is
 * deliberately bounded to the app shell, login, and shared chrome — page
 * content localization is incremental and documented (NATIONAL_SCALE.md).
 */
export const messages = {
  'app.name': 'Swasthya',
  'nav.dashboard': 'Dashboard',
  'nav.patients': 'Patients',
  'nav.appointments': 'Appointments',
  'nav.queue': 'Queue',
  'nav.billing': 'Billing',
  'nav.audit': 'Audit',
  'shell.primary': 'Primary',
  'shell.skipToContent': 'Skip to content',
  'shell.signOut': 'Sign out',
  'shell.more': 'More',
  'shell.moreDestinations': 'More destinations',
  'shell.selectFacilityRequired': 'Select a facility to continue.',
  'shell.chooseFacility': 'Choose facility',
  'shell.facility': 'Facility',
  'shell.resolvingFacility': 'Resolving facility…',
  'shell.restoringSession': 'Restoring session…',
  'facilityChooser.title': 'Choose a facility',
  'facilityChooser.hint': 'You are authorized at more than one facility. Pick one to continue.',
  'login.subtitle': 'Hospital management — sign in to continue',
  'login.email': 'Email',
  'login.password': 'Password',
  'login.signIn': 'Sign in',
  'login.emailPlaceholder': 'you@hospital.example',
  'login.validationError': 'Enter your email and password.',
  'login.rateLimited': 'Too many attempts. Wait a moment and try again.',
  'login.failed': 'Sign-in failed. Check your email and password.',
  'common.loading': 'Loading…',
} as const;

export type MessageKey = keyof typeof messages;
