import type { components, operations } from '@/api/schema';

export type Branch = components['schemas']['Branch'];
export type BranchType = Branch['type'];
export type CostCenter = components['schemas']['CostCenter'];
export type EquipmentFamily = components['schemas']['EquipmentFamily'];
export type EquipmentCategory = EquipmentFamily['category'];
export type Criticality = EquipmentFamily['criticality'];

/** `/meta/enums`: cada cadastro acrescenta as suas chaves (arrays de strings). */
type MetaEnumsData = NonNullable<
  operations['metaEnums']['responses'][200]['content']['application/json']['data']
>;
export type MetaEnums = NonNullable<MetaEnumsData['enums']>;
