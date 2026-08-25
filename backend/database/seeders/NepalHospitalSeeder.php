<?php

namespace Database\Seeders;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Charge;
use App\Models\ClinicalNote;
use App\Models\Department;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Realistic Nepal hospital data seeder for meaningful UAT.
 *
 * Creates a complete hospital with:
 * - Organization, facility, departments, wards, rooms, beds
 * - Services with Nepal-relevant pricing (NPR)
 * - Common Nepal medications
 * - Staff with Nepal names and roles
 * - Patients with Nepal demographics
 * - Appointments, encounters, diagnoses, prescriptions, lab orders
 * - Financial data (charges, invoices, payments)
 *
 * Usage:
 *   php artisan db:seed --class=NepalHospitalSeeder --force
 *
 * Refuses to run on production.
 */
class NepalHospitalSeeder extends Seeder
{
    // Nepal male first names
    private array $maleNames = [
        'Ram', 'Shyam', 'Hari', 'Krishna', 'Bishnu', 'Gopal', 'Rajan',
        'Sanjay', 'Deepak', 'Rajan', 'Prakash', 'Sunil', 'Ramesh', 'Suresh',
        'Mohan', 'Ganesh', 'Bikash', 'Dipak', 'Nabin', 'Santosh', 'Anil',
        'Bharat', 'Dinesh', 'Kamal', 'Narayan', 'Pradeep', 'Ravi', 'Sagar',
        'Vikram', 'Yogesh', 'Ashok', 'Basanta', 'Chandra', 'Devendra',
    ];

    // Nepal female first names
    private array $femaleNames = [
        'Sita', 'Gita', 'Sunita', 'Anita', 'Sarita', 'Kamala', 'Sangita',
        'Rita', 'Nita', 'Laxmi', 'Parbati', 'Durga', 'Radha', 'Krishna',
        'Sushila', 'Hira', 'Mina', 'Puja', 'Rupa', 'Tara', 'Asha',
        'Bishnu', 'Chandra', 'Deepa', 'Ganga', 'Jharna', 'Kailashi',
        'Lila', 'Mala', 'Nirmala', 'Sadhana', 'Shanti', 'Usha',
    ];

    // Nepal last names (common castes/ethnicities)
    private array $lastNames = [
        'Sharma', 'Thapa', 'Gurung', 'Tamang', 'Rai', 'Limbu', 'Magar',
        'Shrestha', 'Adhikari', 'Poudel', 'Karki', 'Pandey', 'KC', 'Basnet',
        'Maharjan', 'Thapa', 'Pariyar', 'BK', 'Chhetri', 'Dahal', 'Subedi',
        'Bhandari', 'Koirala', 'Khatri', 'Lama', 'Sherpa', 'Giri', 'Yadav',
    ];

    // Common Nepal villages/wards
    private array $villages = [
        'Buddhanilkantha', 'Kathmandu', 'Lalitpur', 'Bhaktapur', 'Pokhara',
        'Chitwan', 'Butwal', 'Biratnagar', 'Dharan', 'Janakpur',
        'Hetauda', 'Nepalgunj', 'Dhangadhi', 'Itahari', 'Bharatpur',
        'Damak', 'Tulsipur', 'Ghorahi', 'Inaruwa', 'Bhimdatta',
    ];

    // Common Nepal diagnoses (ICD-10 codes)
    private array $diagnoses = [
        ['code' => 'J06.9', 'desc' => 'Acute upper respiratory infection', 'type' => 'final'],
        ['code' => 'J18.9', 'desc' => 'Pneumonia, unspecified organism', 'type' => 'final'],
        ['code' => 'A09', 'desc' => 'Infectious gastroenteritis', 'type' => 'final'],
        ['code' => 'E11.9', 'desc' => 'Type 2 diabetes mellitus without complications', 'type' => 'final'],
        ['code' => 'I10', 'desc' => 'Essential (primary) hypertension', 'type' => 'final'],
        ['code' => 'M54.5', 'desc' => 'Low back pain', 'type' => 'final'],
        ['code' => 'N39.0', 'desc' => 'Urinary tract infection', 'type' => 'final'],
        ['code' => 'K21.0', 'desc' => 'GERD with esophagitis', 'type' => 'final'],
        ['code' => 'J45.9', 'desc' => 'Asthma, unspecified', 'type' => 'final'],
        ['code' => 'E78.5', 'desc' => 'Hyperlipidemia, unspecified', 'type' => 'final'],
        ['code' => 'F32.9', 'desc' => 'Major depressive disorder, single episode', 'type' => 'final'],
        ['code' => 'M17.1', 'desc' => 'Primary osteoarthritis, knee', 'type' => 'final'],
        ['code' => 'I25.1', 'desc' => 'Atherosclerotic heart disease', 'type' => 'final'],
        ['code' => 'C34.9', 'desc' => 'Malignant neoplasm of bronchus/lung', 'type' => 'provisional'],
        ['code' => 'K80.2', 'desc' => 'Calculus of gallbladder without cholecystitis', 'type' => 'final'],
        ['code' => 'O80', 'desc' => 'Encounter for full-term uncomplicated delivery', 'type' => 'final'],
        ['code' => 'S52.5', 'desc' => 'Fracture of lower end of radius', 'type' => 'final'],
        ['code' => 'L30.9', 'desc' => 'Dermatitis, unspecified', 'type' => 'final'],
        ['code' => 'H10.9', 'desc' => 'Conjunctivitis, unspecified', 'type' => 'final'],
        ['code' => 'B54', 'desc' => 'Unspecified malaria', 'type' => 'provisional'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'NepalHospitalSeeder refuses to run on production (APP_ENV=production).'
            );
        }

        $this->command?->info('🌱 Seeding realistic Nepal hospital data...');

        // 1. Organization & Facility
        $org = $this->createOrganization();
        $facility = $this->createFacility($org);

        // 2. Departments
        $departments = $this->createDepartments($org, $facility);

        // 3. Wards, Rooms, Beds
        $wards = $this->createWards($org, $facility);

        // 4. Services
        $services = $this->createServices($org, $facility, $departments);

        // 5. Medications
        $medications = $this->createMedications($org, $facility);

        // 6. Staff & Users
        $staff = $this->createStaff($org, $facility, $departments);

        // 7. Patients
        $patients = $this->createPatients($org, $facility);

        // 8. Appointments & Encounters
        $encounters = $this->createEncounters($org, $facility, $patients, $staff, $services);

        // 9. Clinical data (diagnoses, notes, prescriptions, lab orders)
        $this->createClinicalData($org, $encounters, $staff, $medications);

        // 10. Admissions
        $this->createAdmissions($org, $facility, $patients, $encounters, $wards);

        // 11. Financial data
        $this->createFinancialData($org, $facility, $patients, $encounters, $services);

        $this->command?->info('✅ Nepal hospital seeder complete.');
        $this->command?->info("   Organization: {$org->name}");
        $this->command?->info("   Facility: {$facility->name}");
        $this->command?->info("   Departments: " . count($departments));
        $this->command?->info("   Wards: " . count($wards));
        $this->command?->info("   Staff: " . count($staff));
        $this->command?->info("   Patients: " . count($patients));
    }

    private function createOrganization(): Organization
    {
        return Organization::updateOrCreate(
            ['code' => 'birat-teaching'],
            [
                'name' => 'Birat Teaching Hospital',
                'status' => Organization::STATUS_ACTIVE,
                'currency' => 'NPR',
                'timezone' => 'Asia/Kathmandu',
                'locale' => 'en',
            ]
        );
    }

    private function createFacility(Organization $org): Facility
    {
        return Facility::updateOrCreate(
            ['tenant_id' => $org->id, 'code' => 'birat-main'],
            [
                'name' => 'Birat Teaching Hospital — Main Campus',
                'status' => Facility::STATUS_ACTIVE,
                'timezone' => 'Asia/Kathmandu',
            ]
        );
    }

    private function createDepartments(Organization $org, Facility $facility): array
    {
        $defs = [
            ['name' => 'Emergency', 'code' => 'emergency', 'type' => 'emergency'],
            ['name' => 'General Medicine', 'code' => 'gmed', 'type' => 'medical'],
            ['name' => 'General Surgery', 'code' => 'gsurg', 'type' => 'surgical'],
            ['name' => 'Orthopedics', 'code' => 'ortho', 'type' => 'surgical'],
            ['name' => 'Obstetrics & Gynaecology', 'code' => 'obgyn', 'type' => 'medical'],
            ['name' => 'Paediatrics', 'code' => 'paeds', 'type' => 'medical'],
            ['name' => 'ENT', 'code' => 'ent', 'type' => 'medical'],
            ['name' => 'Ophthalmology', 'code' => 'ophthal', 'type' => 'medical'],
            ['name' => 'Cardiology', 'code' => 'cardio', 'type' => 'medical'],
            ['name' => 'Nephrology', 'code' => 'nephr', 'type' => 'medical'],
            ['name' => 'ICU', 'code' => 'icu', 'type' => 'medical'],
            ['name' => 'Pharmacy', 'code' => 'pharmacy', 'type' => 'pharmacy'],
            ['name' => 'Laboratory', 'code' => 'lab', 'type' => 'laboratory'],
            ['name' => 'Radiology', 'code' => 'radio', 'type' => 'radiology'],
            ['name' => 'Finance', 'code' => 'finance', 'type' => 'administrative'],
            ['name' => 'HR & Administration', 'code' => 'hr', 'type' => 'administrative'],
            ['name' => 'Blood Bank', 'code' => 'bloodbank', 'type' => 'supportive'],
        ];

        $departments = [];
        foreach ($defs as $d) {
            $departments[$d['code']] = Department::updateOrCreate(
                ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'code' => $d['code']],
                [
                    'name' => $d['name'],
                    'department_type' => $d['type'],
                    'status' => Department::STATUS_ACTIVE,
                ]
            );
        }

        return $departments;
    }

    private function createWards(Organization $org, Facility $facility): array
    {
        $wardDefs = [
            ['name' => 'Medical Ward A', 'code' => 'med-a', 'type' => 'general', 'rooms' => 6, 'beds_per_room' => 4],
            ['name' => 'Medical Ward B', 'code' => 'med-b', 'type' => 'general', 'rooms' => 6, 'beds_per_room' => 4],
            ['name' => 'Surgical Ward', 'code' => 'surg-ward', 'type' => 'surgical', 'rooms' => 5, 'beds_per_room' => 4],
            ['name' => 'Maternity Ward', 'code' => 'maternity', 'type' => 'maternity', 'rooms' => 4, 'beds_per_room' => 3],
            ['name' => 'Paediatric Ward', 'code' => 'paeds-ward', 'type' => 'paediatric', 'rooms' => 3, 'beds_per_room' => 4],
            ['name' => 'ICU', 'code' => 'icu', 'type' => 'icu', 'rooms' => 2, 'beds_per_room' => 6],
            ['name' => 'Emergency Holding', 'code' => 'er-hold', 'type' => 'emergency', 'rooms' => 2, 'beds_per_room' => 4],
        ];

        $wards = [];
        foreach ($wardDefs as $wd) {
            $ward = Ward::updateOrCreate(
                ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'code' => $wd['code']],
                [
                    'name' => $wd['name'],
                    'ward_type' => $wd['type'],
                    'status' => Ward::STATUS_ACTIVE,
                ]
            );

            for ($r = 1; $r <= $wd['rooms']; $r++) {
                $room = Room::updateOrCreate(
                    ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'ward_id' => $ward->id, 'code' => "{$wd['code']}-r{$r}"],
                    [
                        'name' => "{$wd['name']} — Room {$r}",
                        'room_type' => $wd['type'],
                        'daily_rate_minor' => $wd['type'] === 'icu' ? 1500000 : ($wd['type'] === 'maternity' ? 500000 : 300000),
                        'currency' => 'NPR',
                        'status' => Room::STATUS_ACTIVE,
                    ]
                );

                for ($b = 1; $b <= $wd['beds_per_room']; $b++) {
                    Bed::updateOrCreate(
                        ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'room_id' => $room->id, 'bed_code' => "{$wd['code']}-r{$r}-b{$b}"],
                        [
                            'status' => 'available',
                        ]
                    );
                }
            }

            $wards[$wd['code']] = $ward;
        }

        return $wards;
    }

    private function createServices(Organization $org, Facility $facility, array $departments): array
    {
        $serviceDefs = [
            ['code' => 'opd-consult', 'name' => 'OPD Consultation', 'type' => 'opd_consultation', 'dept' => 'gmed', 'price' => 50000, 'duration' => 15],
            ['code' => 'opd-specialist', 'name' => 'Specialist Consultation', 'type' => 'opd_consultation', 'dept' => 'gmed', 'price' => 80000, 'duration' => 20],
            ['code' => 'emergency-consult', 'name' => 'Emergency Consultation', 'type' => 'opd_consultation', 'dept' => 'emergency', 'price' => 100000, 'duration' => 30],
            ['code' => 'ipd-room-general', 'name' => 'IPD General Ward (per day)', 'type' => 'procedure', 'dept' => 'gmed', 'price' => 300000, 'duration' => 1440],
            ['code' => 'ipd-room-icu', 'name' => 'ICU (per day)', 'type' => 'procedure', 'dept' => 'icu', 'price' => 1500000, 'duration' => 1440],
            ['code' => 'lab-cbc', 'name' => 'Complete Blood Count', 'type' => 'investigation', 'dept' => 'lab', 'price' => 25000, 'duration' => 15],
            ['code' => 'lab-rbs', 'name' => 'Random Blood Sugar', 'type' => 'investigation', 'dept' => 'lab', 'price' => 15000, 'duration' => 10],
            ['code' => 'lab-lipid', 'name' => 'Lipid Profile', 'type' => 'investigation', 'dept' => 'lab', 'price' => 45000, 'duration' => 30],
            ['code' => 'lab-kft', 'name' => 'Kidney Function Test', 'type' => 'investigation', 'dept' => 'lab', 'price' => 50000, 'duration' => 30],
            ['code' => 'lab-lft', 'name' => 'Liver Function Test', 'type' => 'investigation', 'dept' => 'lab', 'price' => 50000, 'duration' => 30],
            ['code' => 'lab-tsh', 'name' => 'Thyroid Profile', 'type' => 'investigation', 'dept' => 'lab', 'price' => 60000, 'duration' => 60],
            ['code' => 'lab-urine', 'name' => 'Urinalysis', 'type' => 'investigation', 'dept' => 'lab', 'price' => 15000, 'duration' => 15],
            ['code' => 'lab-hba1c', 'name' => 'HbA1c', 'type' => 'investigation', 'dept' => 'lab', 'price' => 50000, 'duration' => 60],
            ['code' => 'xray-chest', 'name' => 'Chest X-Ray', 'type' => 'investigation', 'dept' => 'radio', 'price' => 40000, 'duration' => 20],
            ['code' => 'xray-spine', 'name' => 'Spine X-Ray', 'type' => 'investigation', 'dept' => 'radio', 'price' => 40000, 'duration' => 20],
            ['code' => 'usg-abdomen', 'name' => 'Abdominal Ultrasound', 'type' => 'investigation', 'dept' => 'radio', 'price' => 80000, 'duration' => 30],
            ['code' => 'echo', 'name' => 'Echocardiography', 'type' => 'investigation', 'dept' => 'cardio', 'price' => 150000, 'duration' => 45],
            ['code' => 'ecg', 'name' => 'ECG', 'type' => 'investigation', 'dept' => 'cardio', 'price' => 30000, 'duration' => 15],
            ['code' => 'procedure-suturing', 'name' => 'Wound Suturing', 'type' => 'procedure', 'dept' => 'emergency', 'price' => 50000, 'duration' => 30],
            ['code' => 'procedure-casting', 'name' => 'Plaster Cast Application', 'type' => 'procedure', 'dept' => 'ortho', 'price' => 80000, 'duration' => 45],
            ['code' => 'delivery-normal', 'name' => 'Normal Delivery', 'type' => 'procedure', 'dept' => 'obgyn', 'price' => 2000000, 'duration' => 120],
            ['code' => 'delivery-cs', 'name' => 'Caesarean Section', 'type' => 'procedure', 'dept' => 'obgyn', 'price' => 5000000, 'duration' => 180],
            ['code' => 'minor-surgery', 'name' => 'Minor Surgery', 'type' => 'procedure', 'dept' => 'gsurg', 'price' => 300000, 'duration' => 60],
        ];

        $services = [];
        foreach ($serviceDefs as $s) {
            $services[$s['code']] = Service::updateOrCreate(
                ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'code' => $s['code']],
                [
                    'name' => $s['name'],
                    'service_type' => $s['type'],
                    'department_id' => $departments[$s['dept']]->id,
                    'default_charge_minor' => $s['price'],
                    'default_duration_minutes' => $s['duration'],
                    'currency' => 'NPR',
                    'status' => Service::STATUS_ACTIVE,
                ]
            );
        }

        return $services;
    }

    private function createMedications(Organization $org, Facility $facility): array
    {
        $medDefs = [
            ['code' => 'para-500', 'generic' => 'Paracetamol', 'brand' => 'Crocin', 'strength' => '500mg', 'form' => 'tablet', 'price' => 3000],
            ['code' => 'amox-500', 'generic' => 'Amoxicillin', 'brand' => 'Amoxicillin', 'strength' => '500mg', 'form' => 'capsule', 'price' => 5000],
            ['code' => 'metformin-500', 'generic' => 'Metformin', 'brand' => 'Glycomet', 'strength' => '500mg', 'form' => 'tablet', 'price' => 4000],
            ['code' => 'amlo-5', 'generic' => 'Amlodipine', 'brand' => 'Amlodac', 'strength' => '5mg', 'form' => 'tablet', 'price' => 6000],
            ['code' => 'omep-20', 'generic' => 'Omeprazole', 'brand' => 'Omez', 'strength' => '20mg', 'form' => 'capsule', 'price' => 5000],
            ['code' => 'ibu-400', 'generic' => 'Ibuprofen', 'brand' => 'Brufen', 'strength' => '400mg', 'form' => 'tablet', 'price' => 3000],
            ['code' => 'cetirizine-10', 'generic' => 'Cetirizine', 'brand' => 'Cetazine', 'strength' => '10mg', 'form' => 'tablet', 'price' => 2000],
            ['code' => 'pantop-40', 'generic' => 'Pantoprazole', 'brand' => 'Pantocid', 'strength' => '40mg', 'form' => 'tablet', 'price' => 7000],
            ['code' => 'azith-500', 'generic' => 'Azithromycin', 'brand' => 'Azee', 'strength' => '500mg', 'form' => 'tablet', 'price' => 12000],
            ['code' => 'metop-50', 'generic' => 'Metoprolol', 'brand' => 'Betaloc', 'strength' => '50mg', 'form' => 'tablet', 'price' => 5000],
            ['code' => 'atorva-10', 'generic' => 'Atorvastatin', 'brand' => 'Atorva', 'strength' => '10mg', 'form' => 'tablet', 'price' => 8000],
            ['code' => 'salbutamol-inh', 'generic' => 'Salbutamol', 'brand' => 'Asthalin', 'strength' => '100mcg', 'form' => 'inhaler', 'price' => 150000],
            ['code' => 'pred-5', 'generic' => 'Prednisolone', 'brand' => 'Prednisolone', 'strength' => '5mg', 'form' => 'tablet', 'price' => 3000],
            ['code' => 'iron-folic', 'generic' => 'Iron + Folic Acid', 'brand' => 'Fesovit', 'strength' => '150mg+0.5mg', 'form' => 'tablet', 'price' => 2000],
            ['code' => 'calcium-d3', 'generic' => 'Calcium + Vitamin D3', 'brand' => 'Calcirol', 'strength' => '500IU', 'form' => 'capsule', 'price' => 8000],
            ['code' => 'metronidazole-400', 'generic' => 'Metronidazole', 'brand' => 'Flagyl', 'strength' => '400mg', 'form' => 'tablet', 'price' => 3000],
            ['code' => 'cipro-500', 'generic' => 'Ciprofloxacin', 'brand' => 'Ciprolox', 'strength' => '500mg', 'form' => 'tablet', 'price' => 6000],
            ['code' => 'diclo-50', 'generic' => 'Diclofenac', 'brand' => 'Volten', 'strength' => '50mg', 'form' => 'tablet', 'price' => 3000],
            ['code' => 'loratadine-10', 'generic' => 'Loratadine', 'brand' => 'Loratin', 'strength' => '10mg', 'form' => 'tablet', 'price' => 4000],
            ['code' => 'ORS', 'generic' => 'Oral Rehydration Salts', 'brand' => 'ORS', 'strength' => '1L sachet', 'form' => 'powder', 'price' => 5000],
        ];

        $medications = [];
        foreach ($medDefs as $m) {
            $medications[$m['code']] = Medication::updateOrCreate(
                ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'code' => $m['code']],
                [
                    'generic_name' => $m['generic'],
                    'brand_name' => $m['brand'],
                    'strength' => $m['strength'],
                    'form' => $m['form'],
                    'unit' => 'tab',
                    'price_minor' => $m['price'],
                    'currency' => 'NPR',
                    'is_controlled' => false,
                    'status' => Medication::STATUS_ACTIVE,
                ]
            );
        }

        return $medications;
    }

    private function createStaff(Organization $org, Facility $facility, array $departments): array
    {
        $staffDefs = [
            // Doctors
            ['name' => 'Dr. Rajan Sharma', 'code' => 'DOC-001', 'dept' => 'gmed', 'role' => 'doctor', 'specialty' => 'General Medicine', 'email' => 'dr.rajan@birat.org', 'fee' => 50000],
            ['name' => 'Dr. Sunita Gurung', 'code' => 'DOC-002', 'dept' => 'gsurg', 'role' => 'doctor', 'specialty' => 'General Surgery', 'email' => 'dr.sunita@birat.org', 'fee' => 80000],
            ['name' => 'Dr. Bikash Thapa', 'code' => 'DOC-003', 'dept' => 'ortho', 'role' => 'doctor', 'specialty' => 'Orthopedics', 'email' => 'dr.bikash@birat.org', 'fee' => 80000],
            ['name' => 'Dr. Kamala Rai', 'code' => 'DOC-004', 'dept' => 'obgyn', 'role' => 'doctor', 'specialty' => 'Obstetrics & Gynaecology', 'email' => 'dr.kamala@birat.org', 'fee' => 80000],
            ['name' => 'Dr. Deepak Pandey', 'code' => 'DOC-005', 'dept' => 'paeds', 'role' => 'doctor', 'specialty' => 'Paediatrics', 'email' => 'dr.deepak@birat.org', 'fee' => 60000],
            ['name' => 'Dr. Nabin Karki', 'code' => 'DOC-006', 'dept' => 'cardio', 'role' => 'doctor', 'specialty' => 'Cardiology', 'email' => 'dr.nabin@birat.org', 'fee' => 100000],
            ['name' => 'Dr. Sagar Shrestha', 'code' => 'DOC-007', 'dept' => 'ent', 'role' => 'doctor', 'specialty' => 'ENT', 'email' => 'dr.sagar@birat.org', 'fee' => 60000],
            ['name' => 'Dr. Ashok Basnet', 'code' => 'DOC-008', 'dept' => 'emergency', 'role' => 'doctor', 'specialty' => 'Emergency Medicine', 'email' => 'dr.ashok@birat.org', 'fee' => 100000],
            // Nurses
            ['name' => 'Sita Tamang', 'code' => 'NUR-001', 'dept' => 'gmed', 'role' => 'nurse', 'email' => 'sita@birat.org'],
            ['name' => 'Gita Magar', 'code' => 'NUR-002', 'dept' => 'surg-ward', 'role' => 'nurse', 'email' => 'gita@birat.org'],
            ['name' => 'Anita Gurung', 'code' => 'NUR-003', 'dept' => 'icu', 'role' => 'nurse', 'email' => 'anita@birat.org'],
            ['name' => 'Sarita Limbu', 'code' => 'NUR-004', 'dept' => 'obgyn', 'role' => 'nurse', 'email' => 'sarita@birat.org'],
            ['name' => 'Rita Chhetri', 'code' => 'NUR-005', 'dept' => 'emergency', 'role' => 'nurse', 'email' => 'rita@birat.org'],
            // Pharmacists
            ['name' => 'Hari Prasad Sharma', 'code' => 'PHA-001', 'dept' => 'pharmacy', 'role' => 'pharmacist', 'email' => 'hari@birat.org'],
            ['name' => 'Mohan Koirala', 'code' => 'PHA-002', 'dept' => 'pharmacy', 'role' => 'pharmacist', 'email' => 'mohan@birat.org'],
            // Lab
            ['name' => 'Dipak Bhandari', 'code' => 'LAB-001', 'dept' => 'lab', 'role' => 'laboratory', 'email' => 'dipak@birat.org'],
            ['name' => 'Nirmala Subedi', 'code' => 'LAB-002', 'dept' => 'lab', 'role' => 'laboratory', 'email' => 'nirmala@birat.org'],
            // Finance
            ['name' => 'Bharat Dahal', 'code' => 'FIN-001', 'dept' => 'finance', 'role' => 'finance', 'email' => 'bharat@birat.org'],
            // Admin
            ['name' => 'Krishna Prasad Poudel', 'code' => 'ADM-001', 'dept' => 'hr', 'role' => 'hospital_admin', 'email' => 'krishna@birat.org'],
        ];

        $staff = [];
        foreach ($staffDefs as $s) {
            $deptCode = $s['dept'];
            $dept = $departments[$deptCode] ?? $departments['gmed'];

            $user = User::updateOrCreate(
                ['email' => $s['email']],
                ['password_hash' => 'UAT2026!', 'status' => 'active']
            );

            $role = Role::where('code', $s['role'])->first();
            if ($role) {
                RoleAssignment::firstOrCreate(
                    ['user_id' => $user->id, 'role_id' => $role->id, 'tenant_id' => $org->id],
                    [
                        'facility_id' => $facility->id,
                        'scope_type' => 'facility',
                        'status' => RoleAssignment::STATUS_ACTIVE,
                        'granted_at' => now(),
                    ]
                );
            }

            $staffObj = Staff::updateOrCreate(
                ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'employee_code' => $s['code']],
                [
                    'department_id' => $dept->id,
                    'user_id' => $user->id,
                    'full_name' => $s['name'],
                    'designation' => $s['role'],
                    'specialty' => $s['specialty'] ?? null,
                    'consultation_fee' => $s['fee'] ?? null,
                    'status' => Staff::STATUS_ACTIVE,
                ]
            );

            $staff[$s['code']] = $staffObj;
        }

        return $staff;
    }

    private function createPatients(Organization $org, Facility $facility): array
    {
        $patients = [];
        $sexes = [Patient::SEX_MALE, Patient::SEX_FEMALE];
        $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', null];

        for ($i = 0; $i < 80; $i++) {
            $sex = $sexes[array_rand($sexes)];
            $firstName = $sex === Patient::SEX_MALE
                ? $this->maleNames[array_rand($this->maleNames)]
                : $this->femaleNames[array_rand($this->femaleNames)];
            $lastName = $this->lastNames[array_rand($this->lastNames)];
            $fullName = "{$firstName} {$lastName}";

            $dob = now()->subYears(rand(1, 85))->subDays(rand(0, 364));
            $phone = '98' . str_pad((string) rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            $village = $this->villages[array_rand($this->villages)];

            $patient = Patient::create([
                'tenant_id' => $org->id,
                'facility_id' => $facility->id,
                'mrn' => 'MRN-' . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'full_name' => $fullName,
                'date_of_birth' => $dob->format('Y-m-d'),
                'sex' => $sex,
                'blood_group' => $bloodGroups[array_rand($bloodGroups)],
                'status' => Patient::STATUS_ACTIVE,
            ]);

            $patients[] = $patient;
        }

        return $patients;
    }

    private function createEncounters(
        Organization $org,
        Facility $facility,
        array $patients,
        array $staff,
        array $services
    ): array {
        $doctors = array_filter($staff, fn($s) => $s->designation === 'doctor');
        $doctors = array_values($doctors);
        $opdService = $services['opd-consult'];

        $encounters = [];
        $today = now();

        // Create encounters for the last 7 days
        for ($day = 0; $day < 7; $day++) {
            $date = $today->copy()->subDays($day);
            $numEncounters = rand(8, 15);

            for ($e = 0; $e < $numEncounters; $e++) {
                $patient = $patients[array_rand($patients)];
                $doctor = $doctors[array_rand($doctors)];
                $hour = rand(9, 16);
                $minute = rand(0, 3) * 15;
                $startsAt = $date->copy()->setTime($hour, $minute);

                // Create appointment
                $appointment = Appointment::create([
                    'tenant_id' => $org->id,
                    'facility_id' => $facility->id,
                    'patient_id' => $patient->id,
                    'provider_staff_id' => $doctor->id,
                    'service_id' => $opdService->id,
                    'appointment_type' => Appointment::TYPE_OPD,
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addMinutes(15),
                    'status' => Appointment::STATUS_COMPLETED,
                    'source' => Appointment::SOURCE_COUNTER,
                ]);

                // Create encounter
                $encounter = Encounter::create([
                    'tenant_id' => $org->id,
                    'facility_id' => $facility->id,
                    'patient_id' => $patient->id,
                    'appointment_id' => $appointment->id,
                    'provider_staff_id' => $doctor->id,
                    'type' => Encounter::TYPE_OPD,
                    'status' => Encounter::STATUS_SIGNED,
                    'started_at' => $startsAt,
                    'ended_at' => $startsAt->copy()->addMinutes(rand(10, 30)),
                    'signed_by' => $doctor->id,
                    'signed_at' => $startsAt->copy()->addMinutes(rand(15, 45)),
                    'disposition' => Encounter::DISPOSITION_HOME,
                ]);

                $encounters[] = $encounter;
            }
        }

        return $encounters;
    }

    private function createClinicalData(
        Organization $org,
        array $encounters,
        array $staff,
        array $medications
    ): void {
        $doctors = array_filter($staff, fn($s) => $s->designation === 'doctor');
        $doctors = array_values($doctors);
        $labStaff = array_filter($staff, fn($s) => $s->designation === 'laboratory');
        $labStaff = array_values($labStaff);
        $pharmacists = array_filter($staff, fn($s) => $s->designation === 'pharmacist');
        $pharmacists = array_values($pharmacists);

        $medArray = array_values($medications);

        foreach ($encounters as $encounter) {
            $doctor = $doctors[array_rand($doctors)];
            $diag = $this->diagnoses[array_rand($this->diagnoses)];

            // Diagnosis (70% chance)
            if (rand(1, 100) <= 70) {
                Diagnosis::create([
                    'tenant_id' => $org->id,
                    'encounter_id' => $encounter->id,
                    'code' => $diag['code'],
                    'coding_system' => 'ICD-10',
                    'description' => $diag['desc'],
                    'diagnosis_type' => $diag['type'],
                    'is_primary' => true,
                    'onset_date' => $encounter->started_at->format('Y-m-d'),
                    'status' => 'active',
                ]);
            }

            // Clinical note (60% chance)
            if (rand(1, 100) <= 60) {
                $complaints = [
                    'Fever for 3 days', 'Cough and cold', 'Abdominal pain',
                    'Headache', 'Body ache', 'Difficulty breathing',
                    'Chest pain', 'Joint pain', 'Skin rash', 'Fatigue',
                ];
                $notes = [
                    'Patient appears comfortable. Vitals stable. No acute distress.',
                    'Mild dehydration noted. Prescribed ORS and paracetamol.',
                    'Chronic condition well-managed. Continue current medications.',
                    'Acute presentation. Investigations ordered. Follow up in 1 week.',
                ];

                ClinicalNote::create([
                    'tenant_id' => $org->id,
                    'encounter_id' => $encounter->id,
                    'note_type' => ClinicalNote::TYPE_CONSULTATION,
                    'author_staff_id' => $doctor->id,
                    'content' => [
                        'complaint' => $complaints[array_rand($complaints)],
                        'history' => 'Patient reports symptoms for the past few days.',
                        'examination' => 'General examination within normal limits.',
                        'assessment' => $notes[array_rand($notes)],
                        'plan' => 'Prescribe medications. Follow up if symptoms persist.',
                    ],
                    'status' => ClinicalNote::STATUS_SIGNED,
                    'signed_at' => $encounter->signed_at,
                ]);
            }

            // Prescription (50% chance)
            if (rand(1, 100) <= 50) {
                $prescription = Prescription::create([
                    'tenant_id' => $org->id,
                    'patient_id' => $encounter->patient_id,
                    'encounter_id' => $encounter->id,
                    'prescriber_staff_id' => $doctor->id,
                    'status' => Prescription::STATUS_ACTIVE,
                ]);

                $numMeds = rand(1, 3);
                for ($m = 0; $m < $numMeds; $m++) {
                    $med = $medArray[array_rand($medArray)];
                    $frequencies = ['Once daily', 'Twice daily', 'Three times daily', 'As needed'];
                    $durations = ['3 days', '5 days', '7 days', '10 days', '14 days'];

                    PrescriptionLine::create([
                        'tenant_id' => $org->id,
                        'prescription_id' => $prescription->id,
                        'medication_id' => $med->id,
                        'dose' => '1 tablet',
                        'route' => 'oral',
                        'frequency' => $frequencies[array_rand($frequencies)],
                        'duration' => $durations[array_rand($durations)],
                        'quantity_minor' => 30,
                        'instructions' => 'Take after food',
                        'status' => PrescriptionLine::STATUS_ORDERED,
                        'line_no' => $m + 1,
                    ]);
                }
            }

            // Lab order (40% chance)
            if (rand(1, 100) <= 40 && !empty($labStaff)) {
                $labTests = [
                    ['code' => 'CBC', 'name' => 'Complete Blood Count', 'value' => '12.5', 'unit' => 'g/dL', 'range' => '12.0-16.0'],
                    ['code' => 'RBS', 'name' => 'Random Blood Sugar', 'value' => '110', 'unit' => 'mg/dL', 'range' => '70-140'],
                    ['code' => 'URINE', 'name' => 'Urinalysis', 'value' => 'Normal', 'unit' => '', 'range' => 'Normal'],
                ];
                $test = $labTests[array_rand($labTests)];

                $labOrder = LabOrder::create([
                    'tenant_id' => $org->id,
                    'facility_id' => $encounter->facility_id,
                    'patient_id' => $encounter->patient_id,
                    'encounter_id' => $encounter->id,
                    'ordered_by_staff_id' => $doctor->id,
                    'priority' => LabOrder::PRIORITY_ROUTINE,
                    'status' => LabOrder::STATUS_VERIFIED,
                    'clinical_indication' => 'Routine investigation',
                    'ordered_at' => $encounter->started_at,
                    'verified_by_staff_id' => $labStaff[array_rand($labStaff)]->id,
                    'verified_at' => $encounter->signed_at,
                ]);

                LabOrderItem::create([
                    'tenant_id' => $org->id,
                    'facility_id' => $encounter->facility_id,
                    'lab_order_id' => $labOrder->id,
                    'result_value' => $test['value'],
                    'result_unit' => $test['unit'],
                    'reference_range' => $test['range'],
                    'entered_by_staff_id' => $labStaff[array_rand($labStaff)]->id,
                    'entered_at' => $encounter->signed_at,
                    'verified_by_staff_id' => $labStaff[array_rand($labStaff)]->id,
                    'verified_at' => $encounter->signed_at,
                ]);
            }
        }
    }

    private function createAdmissions(
        Organization $org,
        Facility $facility,
        array $patients,
        array $encounters,
        array $wards
    ): void {
        // Create 5 admissions for recent encounters
        $wardList = array_values($wards);
        $admissionCount = 0;

        foreach ($encounters as $encounter) {
            if ($admissionCount >= 5) {
                break;
            }

            // 10% chance of admission
            if (rand(1, 100) > 10) {
                continue;
            }

            $ward = $wardList[array_rand($wardList)];
            $availableBed = Bed::where('tenant_id', $org->id)
                ->where('facility_id', $facility->id)
                ->where('status', 'available')
                ->first();

            if (!$availableBed) {
                continue;
            }

            $admission = Admission::create([
                'tenant_id' => $org->id,
                'facility_id' => $facility->id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->id,
                'admission_number' => 'ADM-' . str_pad((string) ($admissionCount + 1), 6, '0', STR_PAD_LEFT),
                'admission_type' => Admission::TYPE_PLANNED,
                'admitted_at' => $encounter->started_at,
                'status' => Admission::STATUS_ADMITTED,
            ]);

            $availableBed->update([
                'status' => 'occupied',
                'current_admission_id' => $admission->id,
            ]);

            $admissionCount++;
        }
    }

    private function createFinancialData(
        Organization $org,
        Facility $facility,
        array $patients,
        array $encounters,
        array $services
    ): void {
        $opdService = $services['opd-consult'];
        $invoiceCount = 0;

        foreach ($encounters as $encounter) {
            if ($invoiceCount >= 30) {
                break;
            }

            // 40% chance of having a financial record
            if (rand(1, 100) > 40) {
                continue;
            }

            $chargeAmount = $opdService->default_charge_minor;
            $taxAmount = (int) ($chargeAmount * 0.13); // 13% VAT

            // Charge
            $charge = Charge::create([
                'tenant_id' => $org->id,
                'facility_id' => $facility->id,
                'patient_id' => $encounter->patient_id,
                'source_type' => Charge::SOURCE_ENCOUNTER,
                'encounter_id' => $encounter->id,
                'description' => 'OPD Consultation',
                'amount_minor' => $chargeAmount,
                'currency' => 'NPR',
                'tax_rate_bps' => 1300,
                'status' => Charge::STATUS_POSTED,
                'charged_at' => $encounter->signed_at ?? $encounter->started_at,
            ]);

            // Invoice
            $invoiceNumber = 'INV-' . date('Ym') . '-' . str_pad((string) ($invoiceCount + 1), 4, '0', STR_PAD_LEFT);
            $invoice = Invoice::create([
                'tenant_id' => $org->id,
                'facility_id' => $facility->id,
                'patient_id' => $encounter->patient_id,
                'invoice_number' => $invoiceNumber,
                'status' => Invoice::STATUS_ISSUED,
                'total_minor' => $chargeAmount + $taxAmount,
                'total_tax_minor' => $taxAmount,
                'paid_minor' => 0,
                'issued_at' => $encounter->signed_at ?? $encounter->started_at,
            ]);

            // 70% chance of payment
            if (rand(1, 100) <= 70) {
                Payment::create([
                    'tenant_id' => $org->id,
                    'facility_id' => $facility->id,
                    'patient_id' => $encounter->patient_id,
                    'invoice_id' => $invoice->id,
                    'amount_minor' => $chargeAmount + $taxAmount,
                    'currency' => 'NPR',
                    'payment_method' => 'cash',
                    'status' => 'completed',
                    'paid_at' => $encounter->signed_at ?? $encounter->started_at,
                ]);

                $invoice->update([
                    'status' => Invoice::STATUS_PAID,
                    'paid_minor' => $chargeAmount + $taxAmount,
                ]);
            }

            $invoiceCount++;
        }
    }
}
