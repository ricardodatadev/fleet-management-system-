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

/** Colaborador vinculado a um usuário, como em /auth/me. */
export interface LinkedEmployee {
  id: number;
  registration: string;
  name: string;
}

/**
 * Item de /users. `employee` entra no contrato junto com a F1-15 (combinado com o backend): até lá
 * a API real não o envia (undefined), e a tela mostra "—". Trocar pelo tipo gerado após o gen:api.
 */
export type User = components['schemas']['User'] & { employee?: LinkedEmployee | null };

/** `/meta/enums`: cada cadastro acrescenta as suas chaves (arrays de strings). */
type MetaEnumsData = NonNullable<
  operations['metaEnums']['responses'][200]['content']['application/json']['data']
>;
export type MetaEnums = NonNullable<MetaEnumsData['enums']>;
