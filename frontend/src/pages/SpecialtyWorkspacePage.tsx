import "./specialty.css";
export function SpecialtyWorkspacePage(_p: { departmentId: string; departmentName: string }) {
  return <div className="page spec-page"><h1 className="page__title">{_p.departmentName}</h1><p>Specialty workspace - profiles, assessments, care plans</p></div>;
}
