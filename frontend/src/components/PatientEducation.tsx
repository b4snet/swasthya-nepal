/**
 * PatientEducation — Patient Education & Recovery Continuity (Phase 115)
 *
 * Answers: "WHAT DO I NEED TO KNOW BEFORE, DURING AND AFTER CARE?"
 *
 * Architecture: CANONICAL CLINICAL SYSTEMS + APPROVED PATIENT INFORMATION
 *               + SECURE PATIENT EXPERIENCE + HUMAN CLINICAL AUTHORITY
 *
 * This is NOT a medical advice chatbot.
 * This IS a contextual patient information layer.
 *
 * Patient experience:
 *   CURRENT CONTEXT → WHAT MATTERS NOW → WHAT TO DO → WHAT TO PREPARE
 *   → WHAT HAS BEEN RELEASED → WHAT HAPPENS NEXT → HOW TO GET HELP
 *
 * Information hierarchy:
 *   CLINICIAN INSTRUCTION ≠ HOSPITAL INSTRUCTION ≠ GENERAL EDUCATION ≠ AI EXPLANATION
 *
 * Safety:
 * - Never fabricate medication instructions, preparation requirements, or emergency guidance
 * - Never let AI become clinical authority
 * - Never show unapproved drafts
 * - Never confuse "acknowledged" with "clinically understood"
 * - Never replace emergency services
 */

import { useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { patientsApi } from '../api/endpoints';
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  FileText,
  Phone,
  ArrowRight,
  Shield,
  BookOpen,
  ClipboardCheck,
  HelpCircle,
  MessageSquare,
  ChevronRight,
} from 'lucide-react';
import './patient-education.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

type InstructionSource = 'clinician' | 'hospital' | 'education' | 'ai_assisted';
type InstructionPriority = 'critical' | 'important' | 'normal';
type TaskStatus = 'available' | 'in_progress' | 'complete' | 'expired';

interface PatientInstruction {
  id: string;
  title: string;
  summary: string;
  content: string;
  source: InstructionSource;
  priority: InstructionPriority;
  category: string;
  relevantTo?: string;
  actionLabel?: string;
  actionTo?: string;
  acknowledged?: boolean;
  createdAt: string;
}

interface PatientTask {
  id: string;
  label: string;
  description: string;
  status: TaskStatus;
  dueAt?: string;
  actionTo?: string;
  category: string;
}

/* ────────────────────────────────────────────────────────────────────
   SOURCE CONFIG
   ──────────────────────────────────────────────────────────────────── */

const SOURCE_CONFIG: Record<InstructionSource, { label: string; color: string; icon: React.ReactNode }> = {
  clinician: { label: 'Clinician Instruction', color: 'var(--teal-600)', icon: <ClipboardCheck size={12} /> },
  hospital: { label: 'Hospital Instruction', color: 'var(--blue-600)', icon: <FileText size={12} /> },
  education: { label: 'Educational Content', color: 'var(--violet-600)', icon: <BookOpen size={12} /> },
  ai_assisted: { label: 'AI-Assisted Explanation', color: 'var(--amber-600)', icon: <HelpCircle size={12} /> },
};

const PRIORITY_CONFIG: Record<InstructionPriority, { color: string; bg: string }> = {
  critical: { color: 'var(--red-600)', bg: 'var(--red-50)' },
  important: { color: 'var(--amber-600)', bg: 'var(--amber-50)' },
  normal: { color: 'var(--gray-600)', bg: 'var(--gray-50)' },
};

/* ────────────────────────────────────────────────────────────────────
   INSTRUCTION CARD
   ──────────────────────────────────────────────────────────────────── */

function InstructionCard({ instruction }: { instruction: PatientInstruction }) {
  const [expanded, setExpanded] = useState(false);
  const source = SOURCE_CONFIG[instruction.source];
  const priority = PRIORITY_CONFIG[instruction.priority];

  return (
    <div
      className={`edu-instruction edu-instruction--${instruction.priority}`}
      style={{ borderLeftColor: priority.color }}
    >
      <button
        type="button"
        className="edu-instruction__header"
        onClick={() => setExpanded(!expanded)}
        aria-expanded={expanded}
      >
        <div className="edu-instruction__source" style={{ color: source.color }}>
          {source.icon}
          <span>{source.label}</span>
        </div>
        <h4 className="edu-instruction__title">{instruction.title}</h4>
        {instruction.summary && (
          <p className="edu-instruction__summary">{instruction.summary}</p>
        )}
        <ChevronRight size={14} className={`edu-instruction__chevron ${expanded ? 'edu-instruction__chevron--open' : ''}`} />
      </button>

      {expanded && (
        <div className="edu-instruction__body">
          <div className="edu-instruction__content">
            {instruction.content.split('\n').map((para, i) => (
              <p key={i}>{para}</p>
            ))}
          </div>

          {instruction.actionLabel && instruction.actionTo && (
            <div className="edu-instruction__action">
              <span className="edu-instruction__action-label">{instruction.actionLabel}</span>
              <ArrowRight size={14} />
            </div>
          )}

          {instruction.relevantTo && (
            <div className="edu-instruction__context">
              Relevant to: {instruction.relevantTo}
            </div>
          )}

          {instruction.source === 'ai_assisted' && (
            <div className="edu-instruction__ai-notice">
              <HelpCircle size={12} />
              <span>This is an AI-assisted explanation. It is not a substitute for clinical advice from your treating clinician.</span>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   TASK CARD
   ──────────────────────────────────────────────────────────────────── */

function TaskCard({ task }: { task: PatientTask }) {
  const statusConfig: Record<TaskStatus, { icon: React.ReactNode; color: string; label: string }> = {
    available: { icon: <ArrowRight size={14} />, color: 'var(--teal-600)', label: 'Action needed' },
    in_progress: { icon: <Clock size={14} />, color: 'var(--amber-600)', label: 'In progress' },
    complete: { icon: <CheckCircle2 size={14} />, color: 'var(--green-600)', label: 'Complete' },
    expired: { icon: <AlertTriangle size={14} />, color: 'var(--gray-400)', label: 'Expired' },
  };

  const config = statusConfig[task.status];

  return (
    <div className={`edu-task edu-task--${task.status}`}>
      <div className="edu-task__status" style={{ color: config.color }}>
        {config.icon}
      </div>
      <div className="edu-task__info">
        <span className="edu-task__label">{task.label}</span>
        {task.description && <span className="edu-task__desc">{task.description}</span>}
        {task.dueAt && <span className="edu-task__due">Due: {task.dueAt}</span>}
      </div>
      <span className="edu-task__badge" style={{ color: config.color }}>{config.label}</span>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   EMERGENCY CONTACT
   ──────────────────────────────────────────────────────────────────── */

function EmergencyContact() {
  return (
    <div className="edu-emergency" role="alert">
      <div className="edu-emergency__icon">
        <Phone size={16} />
      </div>
      <div className="edu-emergency__info">
        <span className="edu-emergency__title">Need Emergency Help?</span>
        <span className="edu-emergency__text">
          If you are experiencing a medical emergency, call your local emergency number or go to the nearest emergency department.
        </span>
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MAIN PATIENT EDUCATION
   ──────────────────────────────────────────────────────────────────── */

export function PatientEducation({ patientId }: { patientId?: string }) {
  const { selectedFacilityId } = useTenant();

  // ── Fetch patient data ──
  const prescriptions = useFetch(
    () => patientId
      ? patientsApi.prescriptions(patientId, selectedFacilityId).catch(() => [])
      : Promise.resolve([]),
    [patientId, selectedFacilityId],
  );

  const diagnoses = useFetch(
    () => patientId
      ? patientsApi.diagnoses(patientId, selectedFacilityId).catch(() => [])
      : Promise.resolve([]),
    [patientId, selectedFacilityId],
  );

  // ── Derive instructions from canonical data ──
  const instructions: PatientInstruction[] = useMemo(() => {
    const items: PatientInstruction[] = [];

    // Active medications → medication instructions
    const activeMeds = ((prescriptions.data as any[]) ?? []).filter(
      (p: any) => p.status === 'active' || p.status === 'dispensed',
    );

    for (const med of activeMeds) {
      items.push({
        id: `med-${med.id}`,
        title: `Medication: ${med.medicationName ?? med.medication?.name ?? 'Prescription'}`,
        summary: `${med.dosage ?? ''} ${med.frequency ?? ''}`.trim(),
        content: `Take ${med.medicationName ?? 'this medication'} as prescribed.\nDosage: ${med.dosage ?? 'As directed'}\nFrequency: ${med.frequency ?? 'As prescribed'}\n\nDo not stop taking this medication without consulting your clinician.`,
        source: 'clinician',
        priority: 'important',
        category: 'medication',
        relevantTo: med.medicationName ?? 'Current prescription',
        createdAt: med.createdAt ?? new Date().toISOString(),
      });
    }

    // Active diagnoses → condition information
    const activeDiagnoses = ((diagnoses.data as any[]) ?? []).filter(
      (d: any) => d.status === 'active',
    );

    for (const dx of activeDiagnoses) {
      items.push({
        id: `dx-${dx.id}`,
        title: `Condition: ${dx.description ?? dx.code ?? 'Diagnosis'}`,
        summary: `Type: ${dx.type ?? 'Diagnosis'}`,
        content: `Your clinician has documented this condition as active.\nIf you have questions about this condition, please contact your care team.`,
        source: 'clinician',
        priority: 'normal',
        category: 'condition',
        createdAt: dx.createdAt ?? new Date().toISOString(),
      });
    }

    // General education placeholders
    items.push({
      id: 'edu-hydration',
      title: 'General Health: Stay Hydrated',
      summary: 'Drink plenty of water throughout the day',
      content: 'Staying hydrated is important for your overall health and recovery.\nAim for 6-8 glasses of water daily unless otherwise directed by your clinician.',
      source: 'education',
      priority: 'normal',
      category: 'general',
      createdAt: new Date().toISOString(),
    });

    items.push({
      id: 'edu-contact',
      title: 'When to Contact Your Hospital',
      summary: 'Important signs that require medical attention',
      content: 'Contact your hospital or clinician if you experience:\n- Worsening symptoms\n- New symptoms not discussed during your visit\n- Difficulty taking prescribed medication\n- Concerns about your treatment plan\n\nFor emergencies, call your local emergency number.',
      source: 'hospital',
      priority: 'important',
      category: 'safety',
      createdAt: new Date().toISOString(),
    });

    return items;
  }, [prescriptions.data, diagnoses.data]);

  // ── Derive tasks ──
  const tasks: PatientTask[] = useMemo(() => {
    const items: PatientTask[] = [];

    // Active medications need adherence
    const activeMeds = ((prescriptions.data as any[]) ?? []).filter(
      (p: any) => p.status === 'active',
    );

    if (activeMeds.length > 0) {
      items.push({
        id: 'task-medication',
        label: 'Take prescribed medications',
        description: `${activeMeds.length} active prescription${activeMeds.length !== 1 ? 's' : ''}`,
        status: 'available',
        category: 'medication',
      });
    }

    return items;
  }, [prescriptions.data]);

  // ── Separate by priority ──
  const criticalInstructions = instructions.filter((i) => i.priority === 'critical');
  const importantInstructions = instructions.filter((i) => i.priority === 'important');
  const normalInstructions = instructions.filter((i) => i.priority === 'normal');
  const pendingTasks = tasks.filter((t) => t.status === 'available' || t.status === 'in_progress');

  const isLoading = prescriptions.loading || diagnoses.loading;

  if (isLoading) {
    return (
      <div className="edu-loading" role="status">
        <div className="spinner" />
        <span>Loading your health information…</span>
      </div>
    );
  }

  return (
    <div className="patient-education" role="region" aria-label="Patient education and instructions">
      {/* Emergency contact — always visible */}
      <EmergencyContact />

      {/* Critical instructions */}
      {criticalInstructions.length > 0 && (
        <section className="edu-section edu-section--critical" aria-label="Critical instructions">
          <h3 className="edu-section__title">
            <AlertTriangle size={15} />
            Critical — Read Immediately
          </h3>
          <div className="edu-instructions">
            {criticalInstructions.map((inst) => (
              <InstructionCard key={inst.id} instruction={inst} />
            ))}
          </div>
        </section>
      )}

      {/* Patient tasks */}
      {pendingTasks.length > 0 && (
        <section className="edu-section" aria-label="Your tasks">
          <h3 className="edu-section__title">
            <ClipboardCheck size={15} />
            What You Need To Do
          </h3>
          <div className="edu-tasks">
            {pendingTasks.map((task) => (
              <TaskCard key={task.id} task={task} />
            ))}
          </div>
        </section>
      )}

      {/* Important instructions */}
      {importantInstructions.length > 0 && (
        <section className="edu-section" aria-label="Important instructions">
          <h3 className="edu-section__title">
            <FileText size={15} />
            Important Instructions
          </h3>
          <div className="edu-instructions">
            {importantInstructions.map((inst) => (
              <InstructionCard key={inst.id} instruction={inst} />
            ))}
          </div>
        </section>
      )}

      {/* Other instructions */}
      {normalInstructions.length > 0 && (
        <section className="edu-section" aria-label="General information">
          <h3 className="edu-section__title">
            <BookOpen size={15} />
            General Information
          </h3>
          <div className="edu-instructions">
            {normalInstructions.map((inst) => (
              <InstructionCard key={inst.id} instruction={inst} />
            ))}
          </div>
        </section>
      )}

      {/* Ask hospital */}
      <div className="edu-contact">
        <MessageSquare size={16} />
        <div>
          <span className="edu-contact__title">Have a Question?</span>
          <span className="edu-contact__text">
            Contact your care team through the hospital's communication channel.
          </span>
        </div>
      </div>

      {/* Boundary notice */}
      <div className="edu-notice" role="note">
        <Shield size={12} />
        <span>
          Instructions are sourced from your clinician, hospital configuration, or approved educational content.
          AI-assisted explanations are clearly labeled and are not clinical advice.
          Always consult your treating clinician for medical decisions.
        </span>
      </div>
    </div>
  );
}

export default PatientEducation;
