/**
 * Nepali (Devanagari) message catalog — key-for-key mirror of `en.ts`.
 *
 * The catalog-parity test (`I18nProvider.test.tsx`) enforces that `ne.ts`
 * never drifts from `en.ts`: a missing Nepali key fails the suite, so an
 * English-only fallback can never silently appear inside the Nepali UI.
 */
import type { MessageKey } from './en';

export const messages: Record<MessageKey, string> = {
  'app.name': 'स्वास्थ्य',
  'nav.dashboard': 'ड्यासबोर्ड',
  'nav.patients': 'बिरामीहरू',
  'nav.appointments': 'अपोइन्टमेन्टहरू',
  'nav.queue': 'कतार',
  'nav.billing': 'बिलिङ',
  'nav.audit': 'अडिट',
  'shell.primary': 'प्राथमिक',
  'shell.skipToContent': 'सामग्रीमा जानुहोस्',
  'shell.signOut': 'साइन आउट',
  'shell.more': 'थप',
  'shell.moreDestinations': 'थप गन्तव्यहरू',
  'shell.selectFacilityRequired': 'जारी राख्न कुनै सुविधा चयन गर्नुहोस्।',
  'shell.chooseFacility': 'सुविधा छान्नुहोस्',
  'shell.facility': 'सुविधा',
  'shell.resolvingFacility': 'सुविधा पत्ता लगाउँदै…',
  'shell.restoringSession': 'सत्र पुनर्स्थापना गर्दै…',
  'facilityChooser.title': 'सुविधा चयन गर्नुहोस्',
  'facilityChooser.hint': 'तपाईं एकभन्दा बढी सुविधामा अधिकृत हुनुहुन्छ। जारी राख्न एउटा छान्नुहोस्।',
  'login.subtitle': 'अस्पताल व्यवस्थापन — जारी राख्न साइन इन गर्नुहोस्',
  'login.email': 'इमेल',
  'login.password': 'पासवर्ड',
  'login.signIn': 'साइन इन',
  'login.emailPlaceholder': 'तपाईं@अस्पताल.उदाहरण',
  'login.validationError': 'कृपया आफ्नो इमेल र पासवर्ड प्रविष्ट गर्नुहोस्।',
  'login.rateLimited': 'धेरै प्रयास भयो। केही बेर पर्खेर फेरि प्रयास गर्नुहोस्।',
  'login.failed': 'साइन इन असफल भयो। आफ्नो इमेल र पासवर्ड जाँच गर्नुहोस्।',
  'common.loading': 'लोड हुँदै…',
  'common.cancel': 'रद्द गर्नुहोस्',
  'common.confirm': 'पुष्टि गर्नुहोस्',
  'login.sessionExpired': 'तपाईंको सत्रको म्याद सकिएको छ। कृपया फेरि साइन इन गर्नुहोस्।',
  'login.sessionRevoked': 'तपाईंको सत्र रद्द गरिएको छ। कृपया फेरि साइन इन गर्नुहोस्।',
  'shell.confirmLogout': 'साइन आउट गर्ने?',
  'shell.confirmLogoutMessage': 'के तपाईं साइन आउट गर्न निश्चित हुनुहुन्छ? तपाईंलाई फेरि प्रमाणपत्र प्रविष्ट गर्नुपर्नेछ।',
  'forbidden.title': 'पहुँच अस्वीकृत',
  'forbidden.message': 'तपाईंलाई यो पृष्ठ हेर्ने अनुमति छैन। यो गलती हो भने आफ्नो प्रशासकसँग सम्पर्क गर्नुहोस्।',
  'forbidden.backToDashboard': 'ड्यासबोर्डमा फर्कनुहोस्',
};
