/**
 * CommandPalette — Universal Clinical Command Layer (Phase 118)
 *
 * Intent-first navigation: the user expresses what they want to do,
 * the system resolves the shortest safe path to the work.
 *
 * Interaction model:
 *   INTENT → CONTEXT → AUTHORIZATION → RELEVANT OPTIONS → WORKSPACE → ACTION → OUTCOME
 *
 * Not an AI system. Not a chatbot. A deterministic clinical navigation system.
 *
 * Design:
 * - Light-first. Clinical. Calm. Fast.
 * - Patient-contextual: shows patient-specific actions when patient is active
 * - Role-aware: only shows authorized commands
 * - Mutation-safe: distinguishes navigation from dangerous actions
 * - Keyboard-accessible: Cmd+K / Ctrl+K, arrow navigation, Enter, Escape
 * - Responsive: works on desktop, tablet, mobile
 */

import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import {
  Search,
  Users,
  Stethoscope,
  Pill,
  FlaskConical,
  ScanLine,
  Bed,
  WalletCards,
  CalendarDays,
  FileText,
  Activity,
  AlertTriangle,
  GitPullRequestArrow,
  Settings,
  BarChart3,
  HeartPulse,
  MessageSquare,
  ClipboardList,
  Clock,
  ArrowRight,
} from 'lucide-react';
import './command-palette.css';

/* ────────────────────────────────────────────────────────────────────
   COMMAND TYPES
   ──────────────────────────────────────────────────────────────────── */

type CommandCategory = 'patient' | 'clinical' | 'care' | 'diagnostics' | 'operations' | 'admin';
type DangerLevel = 'safe' | 'caution' | 'dangerous';

interface ClinicalCommand {
  id: string;
  label: string;
  description?: string;
  category: CommandCategory;
  /** Route to navigate to (may contain :patientId placeholder) */
  route: string;
  /** Whether this command requires an active patient */
  requiresPatient: boolean;
  /** Roles that can see this command */
  roles: string[];
  /** Icon component */
  icon: React.ReactNode;
  /** Aliases for search matching */
  aliases: string[];
  /** Whether this is a mutation (vs navigation) */
  isMutation: boolean;
  /** Danger level for mutations */
  dangerLevel: DangerLevel;
  /** Patient-scoped: if true, route is resolved with active patient ID */
  patientScoped: boolean;
}

interface CommandPaletteProps {
  /** Currently active patient ID (from route context) */
  activePatientId?: string | null;
  /** Currently active patient name (for display) */
  activePatientName?: string;
  /** Currently active patient MRN */
  activePatientMrn?: string;
}

/* ────────────────────────────────────────────────────────────────────
   COMMAND REGISTRY
   ──────────────────────────────────────────────────────────────────── */

const ALL_ROLES: string[] = [];
const CLINICAL_ROLES = ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'];
const DOCTOR_ROLES = ['doctor', 'hospital_admin', 'org_admin', 'superadmin'];
const NURSE_ROLES = ['nurse', 'hospital_admin', 'org_admin', 'superadmin'];
const PHARMACY_ROLES = ['pharmacist', 'hospital_admin', 'org_admin', 'superadmin'];
const LAB_ROLES = ['lab_technician', 'lab_supervisor', 'doctor', 'hospital_admin', 'org_admin', 'superadmin'];
const RADIOLOGY_ROLES = ['radiologist', 'radiographer', 'doctor', 'hospital_admin', 'org_admin', 'superadmin'];
const FINANCE_ROLES = ['billing_clerk', 'hospital_admin', 'org_admin', 'org_finance', 'superadmin'];
const ADMIN_ROLES = ['hospital_admin', 'org_admin', 'superadmin'];

const COMMANDS: ClinicalCommand[] = [
  // ── Patient ──
  { id: 'find-patient', label: 'Find patient', description: 'Search and open a patient record', category: 'patient', route: '/clinical/patients', requiresPatient: false, roles: CLINICAL_ROLES, icon: <Users size={16} />, aliases: ['search patient', 'open patient', 'find', 'search'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'register-patient', label: 'Register patient', description: 'Create a new patient record', category: 'patient', route: '/clinical/patients/new', requiresPatient: false, roles: CLINICAL_ROLES, icon: <Users size={16} />, aliases: ['new patient', 'add patient', 'register'], isMutation: true, dangerLevel: 'safe', patientScoped: false },

  // ── Clinical (patient-scoped) ──
  { id: 'patient-overview', label: 'Patient overview', description: 'Current status and recent activity', category: 'clinical', route: '/clinical/patients/:patientId?ws=overview', requiresPatient: true, roles: CLINICAL_ROLES, icon: <Activity size={16} />, aliases: ['overview', 'summary', 'status'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-timeline', label: 'Patient timeline', description: 'Longitudinal clinical history', category: 'clinical', route: '/clinical/patients/:patientId?ws=timeline', requiresPatient: true, roles: CLINICAL_ROLES, icon: <Clock size={16} />, aliases: ['timeline', 'history', 'chronological'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-encounters', label: 'Patient encounters', description: 'Clinical visit records', category: 'clinical', route: '/clinical/patients/:patientId?ws=encounters', requiresPatient: true, roles: CLINICAL_ROLES, icon: <Stethoscope size={16} />, aliases: ['encounters', 'visits', 'consultations'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-diagnoses', label: 'Patient diagnoses', description: 'Problems and diagnoses', category: 'clinical', route: '/clinical/patients/:patientId?ws=diagnoses', requiresPatient: true, roles: DOCTOR_ROLES, icon: <ClipboardList size={16} />, aliases: ['diagnoses', 'diagnosis', 'problems', 'conditions'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-medications', label: 'Patient medications', description: 'Prescriptions and medications', category: 'clinical', route: '/clinical/patients/:patientId?ws=medications', requiresPatient: true, roles: CLINICAL_ROLES, icon: <Pill size={16} />, aliases: ['medications', 'meds', 'prescriptions', 'rx'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-results', label: 'Patient results', description: 'Lab orders and results', category: 'diagnostics', route: '/clinical/patients/:patientId?ws=lab', requiresPatient: true, roles: CLINICAL_ROLES, icon: <FlaskConical size={16} />, aliases: ['results', 'labs', 'lab results', 'test results', 'laboratory'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-imaging', label: 'Patient imaging', description: 'Radiology orders and reports', category: 'diagnostics', route: '/clinical/patients/:patientId?ws=radiology', requiresPatient: true, roles: RADIOLOGY_ROLES, icon: <ScanLine size={16} />, aliases: ['imaging', 'radiology', 'xray', 'x-ray', 'scan'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-admissions', label: 'Patient admissions', description: 'Inpatient admissions', category: 'care', route: '/clinical/patients/:patientId?ws=admissions', requiresPatient: true, roles: CLINICAL_ROLES, icon: <Bed size={16} />, aliases: ['admissions', 'admit', 'inpatient', 'ipd'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-referrals', label: 'Patient referrals', description: 'Internal and external referrals', category: 'clinical', route: '/clinical/patients/:patientId?ws=referrals', requiresPatient: true, roles: DOCTOR_ROLES, icon: <GitPullRequestArrow size={16} />, aliases: ['referrals', 'refer', 'consult'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-appointments', label: 'Patient appointments', description: 'Scheduled visits and follow-ups', category: 'clinical', route: '/clinical/patients/:patientId?ws=appointments', requiresPatient: true, roles: ALL_ROLES, icon: <CalendarDays size={16} />, aliases: ['appointments', 'schedule', 'follow-up', 'booking'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-documents', label: 'Patient documents', description: 'Notes, consents, and records', category: 'clinical', route: '/clinical/patients/:patientId?ws=documents', requiresPatient: true, roles: ALL_ROLES, icon: <FileText size={16} />, aliases: ['documents', 'notes', 'forms', 'consents'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-communication', label: 'Patient communication', description: 'Care-team messages', category: 'clinical', route: '/clinical/patients/:patientId?ws=communication', requiresPatient: true, roles: ALL_ROLES, icon: <MessageSquare size={16} />, aliases: ['communication', 'messages', 'threads', 'chat'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-careteam', label: 'Patient care team', description: 'Current care team and responsibilities', category: 'care', route: '/clinical/patients/:patientId?ws=careteam', requiresPatient: true, roles: CLINICAL_ROLES, icon: <Users size={16} />, aliases: ['care team', 'team', 'providers'], isMutation: false, dangerLevel: 'safe', patientScoped: true },
  { id: 'patient-loops', label: 'Open loops', description: 'Clinical workflow follow-through', category: 'care', route: '/clinical/patients/:patientId?ws=loops', requiresPatient: true, roles: CLINICAL_ROLES, icon: <AlertTriangle size={16} />, aliases: ['open loops', 'loops', 'pending', 'follow-through'], isMutation: false, dangerLevel: 'safe', patientScoped: true },

  // ── Clinical actions (non-patient-scoped) ──
  { id: 'appointments', label: 'Appointments', description: 'Schedule and manage visits', category: 'clinical', route: '/clinical/appointments', requiresPatient: false, roles: CLINICAL_ROLES, icon: <CalendarDays size={16} />, aliases: ['appointments', 'schedule', 'booking'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'my-work', label: 'My Work', description: 'Clinical work queue — pending items across all patients', category: 'clinical', route: '/staff?ws=workqueue', requiresPatient: false, roles: CLINICAL_ROLES, icon: <ClipboardList size={16} />, aliases: ['my work', 'work queue', 'pending work', 'tasks', 'todo'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'queue', label: 'Patient queue', description: 'Active patient queue', category: 'clinical', route: '/clinical/queue', requiresPatient: false, roles: CLINICAL_ROLES, icon: <ClipboardList size={16} />, aliases: ['queue', 'waiting', 'opd'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'encounters', label: 'Encounters', description: 'Clinical encounter records', category: 'clinical', route: '/clinical/encounters', requiresPatient: false, roles: DOCTOR_ROLES, icon: <Stethoscope size={16} />, aliases: ['encounters', 'visits', 'consultations'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'orders', label: 'Orders', description: 'Clinical orders and forms', category: 'clinical', route: '/clinical/forms', requiresPatient: false, roles: CLINICAL_ROLES, icon: <ClipboardList size={16} />, aliases: ['orders', 'forms', 'requests'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'referrals', label: 'Referrals', description: 'Patient referrals', category: 'clinical', route: '/clinical/referrals', requiresPatient: false, roles: DOCTOR_ROLES, icon: <GitPullRequestArrow size={16} />, aliases: ['referrals', 'refer', 'consult'], isMutation: false, dangerLevel: 'safe', patientScoped: false },

  // ── Care ──
  { id: 'emergency', label: 'Emergency', description: 'Emergency department', category: 'care', route: '/emergency', requiresPatient: false, roles: ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'], icon: <HeartPulse size={16} />, aliases: ['emergency', 'er', 'trauma', 'acute'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'nursing', label: 'Nursing', description: 'Nursing tasks and rounds', category: 'care', route: '/nursing', requiresPatient: false, roles: NURSE_ROLES, icon: <ClipboardList size={16} />, aliases: ['nursing', 'nurse', 'tasks', 'rounds'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'ipd', label: 'Inpatient', description: 'Inpatient admissions', category: 'care', route: '/ipd', requiresPatient: false, roles: CLINICAL_ROLES, icon: <Bed size={16} />, aliases: ['inpatient', 'ipd', 'admissions', 'ward'], isMutation: false, dangerLevel: 'safe', patientScoped: false },

  // ── Diagnostics ──
  { id: 'laboratory', label: 'Laboratory', description: 'Lab orders and results', category: 'diagnostics', route: '/laboratory', requiresPatient: false, roles: LAB_ROLES, icon: <FlaskConical size={16} />, aliases: ['laboratory', 'lab', 'tests', 'specimens'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'radiology', label: 'Radiology', description: 'Imaging studies and reports', category: 'diagnostics', route: '/radiology', requiresPatient: false, roles: RADIOLOGY_ROLES, icon: <ScanLine size={16} />, aliases: ['radiology', 'imaging', 'xray', 'scan'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'critical-values', label: 'Critical values', description: 'Critical value alerts', category: 'diagnostics', route: '/laboratory/critical-values', requiresPatient: false, roles: LAB_ROLES, icon: <AlertTriangle size={16} />, aliases: ['critical values', 'critical', 'alerts', 'stat'], isMutation: false, dangerLevel: 'safe', patientScoped: false },

  // ── Operations ──
  { id: 'pharmacy', label: 'Pharmacy', description: 'Prescriptions and dispensing', category: 'operations', route: '/pharmacy', requiresPatient: false, roles: PHARMACY_ROLES, icon: <Pill size={16} />, aliases: ['pharmacy', 'dispensing', 'medications'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'billing', label: 'Billing', description: 'Billing and invoices', category: 'operations', route: '/finance/billing', requiresPatient: false, roles: FINANCE_ROLES, icon: <WalletCards size={16} />, aliases: ['billing', 'invoices', 'charges', 'payment'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'documents', label: 'Documents', description: 'Document center', category: 'operations', route: '/reports/documents', requiresPatient: false, roles: ADMIN_ROLES, icon: <FileText size={16} />, aliases: ['documents', 'files', 'records'], isMutation: false, dangerLevel: 'safe', patientScoped: false },

  // ── Admin ──
  { id: 'analytics', label: 'Analytics', description: 'Analytics dashboard', category: 'admin', route: '/reports/analytics', requiresPatient: false, roles: ADMIN_ROLES, icon: <BarChart3 size={16} />, aliases: ['analytics', 'reports', 'metrics'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
  { id: 'admin', label: 'Administration', description: 'System administration', category: 'admin', route: '/admin', requiresPatient: false, roles: ADMIN_ROLES, icon: <Settings size={16} />, aliases: ['admin', 'settings', 'configuration'], isMutation: false, dangerLevel: 'safe', patientScoped: false },
];

/* ────────────────────────────────────────────────────────────────────
   CATEGORY CONFIG
   ──────────────────────────────────────────────────────────────────── */

const CATEGORY_CONFIG: Record<CommandCategory, { label: string; color: string }> = {
  patient: { label: 'Patient', color: 'var(--teal-600)' },
  clinical: { label: 'Clinical', color: 'var(--blue-600)' },
  care: { label: 'Care', color: 'var(--green-600)' },
  diagnostics: { label: 'Diagnostics', color: 'var(--violet-600)' },
  operations: { label: 'Operations', color: 'var(--amber-600)' },
  admin: { label: 'Administration', color: 'var(--gray-600)' },
};

/* ────────────────────────────────────────────────────────────────────
   RECENT CONTEXT
   ──────────────────────────────────────────────────────────────────── */

interface RecentPatient {
  id: string;
  name: string;
  mrn: string;
}

const RECENT_STORAGE_KEY = 'swasthya.cmd.recentPatients';

function getRecentPatients(): RecentPatient[] {
  try {
    const raw = localStorage.getItem(RECENT_STORAGE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
}

function addRecentPatient(patient: RecentPatient) {
  const recent = getRecentPatients().filter((p) => p.id !== patient.id);
  recent.unshift(patient);
  localStorage.setItem(RECENT_STORAGE_KEY, JSON.stringify(recent.slice(0, 5)));
}

/* ────────────────────────────────────────────────────────────────────
   MAIN COMMAND PALETTE
   ──────────────────────────────────────────────────────────────────── */

export function CommandPalette({
  activePatientId,
  activePatientName,
  activePatientMrn,
}: CommandPaletteProps = {}) {
  const navigate = useNavigate();
  const { hasRole } = useTenant();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [selectedIndex, setSelectedIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  // Recent patients
  const [recentPatients, setRecentPatients] = useState<RecentPatient[]>([]);
  useEffect(() => {
    setRecentPatients(getRecentPatients());
  }, [open]);

  // Save current patient to recent when patient context changes
  useEffect(() => {
    if (activePatientId && activePatientName) {
      addRecentPatient({ id: activePatientId, name: activePatientName, mrn: activePatientMrn || '' });
    }
  }, [activePatientId, activePatientName, activePatientMrn]);

  // ── Filter commands by role and context ──
  const availableCommands = useMemo(() => {
    return COMMANDS.filter((cmd) => {
      // Role check
      if (cmd.roles.length > 0 && !cmd.roles.some((r) => hasRole(r))) return false;
      return true;
    });
  }, [hasRole]);

  // ── Build patient-scoped commands when patient is active ──
  const allCommands = useMemo(() => {
    const result = [...availableCommands];

    if (activePatientId) {
      // Add patient-scoped commands with resolved routes
      const patientCmds = availableCommands
        .filter((cmd) => cmd.patientScoped && cmd.requiresPatient)
        .map((cmd) => ({
          ...cmd,
          id: `patient:${cmd.id}`,
          route: cmd.route.replace(':patientId', activePatientId),
          label: cmd.label.replace('Patient ', ''),
          description: cmd.description,
        }));
      result.push(...patientCmds);
    }

    return result;
  }, [availableCommands, activePatientId]);

  // ── Search and filter ──
  const filtered = useMemo(() => {
    if (!query.trim()) {
      // Show patient-scoped commands first when patient is active
      if (activePatientId) {
        const patientCmds = allCommands.filter((c) => c.patientScoped);
        const otherCmds = allCommands.filter((c) => !c.patientScoped);
        return [...patientCmds, ...otherCmds];
      }
      return allCommands;
    }

    const q = query.toLowerCase().trim();
    return allCommands.filter((cmd) => {
      if (cmd.label.toLowerCase().includes(q)) return true;
      if (cmd.description?.toLowerCase().includes(q)) return true;
      if (cmd.aliases.some((a) => a.toLowerCase().includes(q))) return true;
      if (CATEGORY_CONFIG[cmd.category]?.label.toLowerCase().includes(q)) return true;
      return false;
    });
  }, [query, allCommands, activePatientId]);

  // ── Group by category ──
  const grouped = useMemo(() => {
    const groups: Record<string, typeof filtered> = {};
    for (const cmd of filtered) {
      const catLabel = CATEGORY_CONFIG[cmd.category]?.label || cmd.category;
      if (!groups[catLabel]) groups[catLabel] = [];
      groups[catLabel].push(cmd);
    }
    return groups;
  }, [filtered]);

  // ── Keyboard shortcut ──
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setOpen((prev) => !prev);
        setQuery('');
        setSelectedIndex(0);
      }
      if (e.key === 'Escape' && open) {
        setOpen(false);
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [open]);

  // ── Focus management ──
  useEffect(() => {
    if (open) {
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  // ── Execute command ──
  const executeCommand = useCallback((cmd: ClinicalCommand) => {
    // Mutation safeguard: navigate to workspace, don't auto-execute
    if (cmd.isMutation && cmd.dangerLevel !== 'safe') {
      // For dangerous mutations, just navigate to the workspace
      // The workspace will handle confirmation
      navigate(cmd.route);
    } else {
      navigate(cmd.route);
    }
    setOpen(false);
  }, [navigate]);

  // ── Open recent patient ──
  const openRecentPatient = useCallback((patient: RecentPatient) => {
    navigate(`/clinical/patients/${patient.id}?ws=overview`);
    setOpen(false);
  }, [navigate]);

  // ── Keyboard navigation ──
  const handleKeyDown = (e: React.KeyboardEvent) => {
    const totalItems = filtered.length + (recentPatients.length > 0 && !query ? recentPatients.length : 0);
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelectedIndex((i) => Math.min(i + 1, totalItems - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelectedIndex((i) => Math.max(i - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      // Check if index is in recent patients section
      if (!query && recentPatients.length > 0 && selectedIndex < recentPatients.length) {
        openRecentPatient(recentPatients[selectedIndex]);
      } else {
        const cmdIndex = query ? selectedIndex : selectedIndex - (!query && recentPatients.length > 0 ? recentPatients.length : 0);
        if (filtered[cmdIndex]) {
          executeCommand(filtered[cmdIndex]);
        }
      }
    }
  };

  // ── Scroll selected into view ──
  useEffect(() => {
    const el = listRef.current?.querySelector('[data-selected="true"]') as HTMLElement | undefined;
    el?.scrollIntoView({ block: 'nearest' });
  }, [selectedIndex]);

  if (!open) return null;

  const showRecent = !query && recentPatients.length > 0;

  return (
    <div className="cmd-backdrop" onClick={() => setOpen(false)}>
      <div className="cmd-palette" role="dialog" aria-modal="true" aria-label="Clinical command palette" onClick={(e) => e.stopPropagation()}>
        {/* Patient context indicator */}
        {activePatientId && (
          <div className="cmd-context">
            <span className="cmd-context__label">Working with</span>
            <span className="cmd-context__name">{activePatientName}</span>
            {activePatientMrn && <span className="cmd-context__mrn mono">{activePatientMrn}</span>}
            <span className="cmd-context__lock" title="Patient context locked">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
            </span>
          </div>
        )}

        {/* Search input */}
        <div className="cmd-input-wrap">
          <Search size={16} className="cmd-icon" />
          <input
            ref={inputRef}
            className="cmd-input"
            placeholder={activePatientId ? `Actions for ${activePatientName}…` : 'Search actions, patients, workspaces…'}
            value={query}
            onChange={(e) => { setQuery(e.target.value); setSelectedIndex(0); }}
            onKeyDown={handleKeyDown}
            autoComplete="off"
            spellCheck={false}
          />
          <kbd className="cmd-kbd">ESC</kbd>
        </div>

        {/* Command list */}
        <div className="cmd-list" ref={listRef}>
          {filtered.length === 0 && !showRecent ? (
            <div className="cmd-empty">
              <span className="cmd-empty__icon"><Search size={24} /></span>
              <span className="cmd-empty__text">No matching action</span>
              <span className="cmd-empty__hint">Try different keywords or check your permissions</span>
            </div>
          ) : (
            <>
              {/* Recent patients */}
              {showRecent && (
                <div className="cmd-group">
                  <div className="cmd-group-label">Recent patients</div>
                  {recentPatients.map((patient, i) => (
                    <button
                      key={patient.id}
                      className={`cmd-item ${selectedIndex === i ? 'cmd-item--selected' : ''}`}
                      data-selected={selectedIndex === i ? 'true' : undefined}
                      onClick={() => openRecentPatient(patient)}
                      onMouseEnter={() => setSelectedIndex(i)}
                    >
                      <span className="cmd-item-icon"><Users size={14} /></span>
                      <span className="cmd-item-content">
                        <span className="cmd-item-label">{patient.name}</span>
                        <span className="cmd-item-meta mono">{patient.mrn}</span>
                      </span>
                      <ArrowRight size={12} className="cmd-item-arrow" />
                    </button>
                  ))}
                </div>
              )}

              {/* Command groups */}
              {Object.entries(grouped).map(([category, commands]) => (
                <div key={category} className="cmd-group">
                  <div className="cmd-group-label">{category}</div>
                  {commands.map((cmd) => {
                    const idx = showRecent
                      ? recentPatients.length + filtered.indexOf(cmd)
                      : filtered.indexOf(cmd);
                    return (
                      <button
                        key={cmd.id}
                        className={`cmd-item ${cmd.dangerLevel === 'dangerous' ? 'cmd-item--dangerous' : ''} ${idx === selectedIndex ? 'cmd-item--selected' : ''}`}
                        data-selected={idx === selectedIndex ? 'true' : undefined}
                        onClick={() => executeCommand(cmd)}
                        onMouseEnter={() => setSelectedIndex(idx)}
                      >
                        <span className="cmd-item-icon" style={{ color: CATEGORY_CONFIG[cmd.category]?.color }}>
                          {cmd.icon}
                        </span>
                        <span className="cmd-item-content">
                          <span className="cmd-item-label">{cmd.label}</span>
                          {cmd.description && (
                            <span className="cmd-item-desc">{cmd.description}</span>
                          )}
                        </span>
                        {cmd.isMutation && cmd.dangerLevel === 'dangerous' && (
                          <span className="cmd-item-badge cmd-item-badge--danger">Action</span>
                        )}
                        {cmd.patientScoped && (
                          <span className="cmd-item-badge cmd-item-badge--patient">Patient</span>
                        )}
                        <ArrowRight size={12} className="cmd-item-arrow" />
                      </button>
                    );
                  })}
                </div>
              ))}
            </>
          )}
        </div>

        {/* Footer */}
        <div className="cmd-footer">
          <span><kbd>↑↓</kbd> navigate</span>
          <span><kbd>↵</kbd> select</span>
          <span><kbd>esc</kbd> close</span>
          {activePatientId && (
            <span className="cmd-footer__context">
              <span className="cmd-footer__dot" /> Patient context active
            </span>
          )}
        </div>
      </div>
    </div>
  );
}

export default CommandPalette;
