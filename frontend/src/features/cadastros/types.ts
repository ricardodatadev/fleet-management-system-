import type { components, operations } from '@/api/schema';

export type Branch = components['schemas']['Branch'];
export type BranchType = Branch['type'];
export type CostCenter = components['schemas']['CostCenter'];
export type EquipmentFamily = components['schemas']['EquipmentFamily'];
export type EquipmentCategory = EquipmentFamily['category'];
export type Criticality = EquipmentFamily['criticality'];
export type Employee = components['schemas']['Employee'];
export type JobType = Employee['job_type'];
export type CnhCategory = NonNullable<Employee['cnh_category']>;

/** Item de /users, com o colaborador vinculado (`employee`, ou null) desde a F1-15. */
export type User = components['schemas']['User'];

/** `/meta/enums`: cada cadastro acrescenta as suas chaves (arrays de strings). */
type MetaEnumsData = NonNullable<
  operations['metaEnums']['responses'][200]['content']['application/json']['data']
>;
export type MetaEnums = NonNullable<MetaEnumsData['enums']>;
