<?php 

namespace Modules\HRM\Interfaces\Employee;

interface EmployeePolicyRepositoryInterface
{
    public function getAllEmployeePolicies();
    public function getEmployeePolicyById($id);
    public function createEmployeePolicy(array $data);
    public function updateEmployeePolicy($id, array $data);
    public function deleteEmployeePolicy($id);

    public function getEmployeePoliciesByEmployeeId($employeeId);
    public function getEmployeeSupervisor($employeeId);
}