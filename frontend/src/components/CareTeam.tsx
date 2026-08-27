import { useMemo } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { encountersApi } from '../api/endpoints';
import type { Encounter } from '../api/types';
import { EmptyState, StatusChip } from './ui';
import {
  Users, User, Stethoscope, Clock, AlertTriangle,
  Activity, FileText, Shield,
} from 'lucide-react';
import './care-team.css';

/* ------------------------------------------------------------------
   CARE TEAM — Make Clinical Responsibility Visible
   
   This is NOT a chat app. It is NOT a task manager.
   It answers: WHO OWNS THIS PATIENT'S WORK RIGHT NOW?
   
   Architecture: PATIENT → EPISODE → CARE TEAM → OWNERSHIP → PENDING → NEXT
   ------------------------------------------------------------------ */

export type TeamRole = 'attending' | 'nursing' | 'consultant' | 'pharmacy' | 'lab' | 'radiology' | 'other';
export type TeamMemberStatus = 'active' | 'on-leave' | 'unavailable';

export interface CareTeamMember {
  id: string;
  name: string;
  role: TeamRole;
  responsibility: string;
  status: TeamMemberStatus;
  isPrimary: boolean;
}

export interface PendingWork {
  id: string;
  type: string;
  label: string;
  assignee?: string;
  status: 'ready' | 'waiting' | 'blocked';
  dueAt?: string;
}

interface CareTeamProps {
  patientId: string;
}

/* ── Derive care team from real data ── */

function deriveCareTeam(
  encounters: Encounter[],
): CareTeamMember[] {
  const team: CareTeamMember[] = [];
  const seen = new Set<string>();

  const activeEncounters = encounters.filter(
    (e) => e.status === 'open' || e.status === 'in_progress',
  );

  for (const enc of activeEncounters) {
    // Provider (attending)
    if (enc.provider?.id && !seen.has(enc.provider.id)) {
      seen.add(enc.provider.id);
      team.push({
        id: enc.provider.id,
        name: enc.provider?.fullName ?? 'Provider',
        role: 'attending',
        responsibility: 'Primary clinician',
        status: 'active',
        isPrimary: true,
      });
    }
  }

  return team;
}

/* ── Derive pending work from encounters ── */

function derivePendingWork(encounters: Encounter[]): PendingWork[] {
  const work: PendingWork[] = [];

  const activeEncounters = encounters.filter(
    (e) => e.status === 'open' || e.status === 'in_progress',
  );

  for (const enc of activeEncounters) {
    work.push({
      id: enc.id,
      type: 'encounter',
      label: `Encounter — ${enc.type}`,
      assignee: enc.provider?.fullName ?? undefined,
      status: enc.status === 'in_progress' ? 'ready' : 'waiting',
    });
  }

  return work;
}

/* ── Role icon helper ── */

function RoleIcon({ role }: { role: TeamRole }) {
  switch (role) {
    case 'attending': return <Stethoscope size={14} />;
    case 'nursing': return <Activity size={14} />;
    case 'consultant': return <Users size={14} />;
    case 'pharmacy': return <FileText size={14} />;
    case 'lab': return <FileText size={14} />;
    case 'radiology': return <FileText size={14} />;
    default: return <User size={14} />;
  }
}

/* ── Team Member Card ── */

function TeamMemberCard({ member }: { member: CareTeamMember }) {
  return (
    <div
      className={`care-member care-member--${member.status} ${member.isPrimary ? 'care-member--primary' : ''}`}
      role="article"
      aria-label={`${member.name}, ${member.role}, ${member.responsibility}`}
    >
      <div className="care-member__avatar">
        {member.name.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase()}
      </div>
      <div className="care-member__info">
        <div className="care-member__name">{member.name}</div>
        <div className="care-member__role">
          <RoleIcon role={member.role} />
          <span>{member.role}</span>
        </div>
        <div className="care-member__responsibility">{member.responsibility}</div>
      </div>
      {member.isPrimary && (
        <span className="care-member__badge">
          <Shield size={10} />
          Primary
        </span>
      )}
      {member.status !== 'active' && (
        <StatusChip
          tone={member.status === 'on-leave' ? 'warning' : 'neutral'}
          label={member.status}
        />
      )}
    </div>
  );
}

/* ── Pending Work Item ── */

function PendingWorkItem({ work }: { work: PendingWork }) {
  const statusColor =
    work.status === 'blocked' ? 'danger' :
    work.status === 'ready' ? 'success' :
    'info';

  return (
    <div className={`care-work care-work--${work.status}`}>
      <div className="care-work__status">
        <StatusChip tone={statusColor} label={work.status} />
      </div>
      <div className="care-work__info">
        <span className="care-work__label">{work.label}</span>
        {work.assignee && (
          <span className="care-work__assignee">
            <User size={10} />
            {work.assignee}
          </span>
        )}
      </div>
    </div>
  );
}

/* ── Main CareTeam ── */

export function CareTeam({ patientId }: CareTeamProps) {
  const { selectedFacilityId } = useTenant();

  const encounters = useFetch(
    () => encountersApi.forPatient(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const team = useMemo(
    () => deriveCareTeam((encounters.data as unknown as Encounter[] ?? [])),
    [encounters.data],
  );

  const pendingWork = useMemo(
    () => derivePendingWork((encounters.data as unknown as Encounter[] ?? [])),
    [encounters.data],
  );

  const blockedWork = pendingWork.filter((w) => w.status === 'blocked');
  const readyWork = pendingWork.filter((w) => w.status === 'ready');

  if (encounters.loading && team.length === 0) {
    return (
      <div className="care-loading" role="status">
        <div className="spinner" />
        <span>Loading care team…</span>
      </div>
    );
  }

  return (
    <div className="care-team" role="region" aria-label="Care team">
      {/* Team members */}
      <div className="care-section">
        <div className="care-section__header">
          <h3 className="care-section__title">
            <Users size={16} />
            Current Care Team
          </h3>
          <span className="care-section__count">{team.length} member{team.length !== 1 ? 's' : ''}</span>
        </div>

        {team.length === 0 ? (
          <EmptyState
            title="No active care team"
            body="Care team members appear when an encounter is active."
          />
        ) : (
          <div className="care-members" role="list" aria-label="Team members">
            {team.map((member) => (
              <TeamMemberCard key={member.id} member={member} />
            ))}
          </div>
        )}
      </div>

      {/* Pending work */}
      {pendingWork.length > 0 && (
        <div className="care-section">
          <div className="care-section__header">
            <h3 className="care-section__title">
              <Clock size={16} />
              Pending Work
            </h3>
            <span className="care-section__count">{pendingWork.length} item{pendingWork.length !== 1 ? 's' : ''}</span>
          </div>

          <div className="care-work-list" role="list" aria-label="Pending work">
            {readyWork.map((work) => (
              <PendingWorkItem key={work.id} work={work} />
            ))}
            {blockedWork.map((work) => (
              <PendingWorkItem key={work.id} work={work} />
            ))}
          </div>
        </div>
      )}

      {/* Blocked work alert */}
      {blockedWork.length > 0 && (
        <div className="care-alert care-alert--blocked" role="alert">
          <AlertTriangle size={14} />
          <span>{blockedWork.length} work item{blockedWork.length !== 1 ? 's' : ''} blocked</span>
        </div>
      )}
    </div>
  );
}

export default CareTeam;
