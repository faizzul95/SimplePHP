<?php includeTemplate('header'); ?>

<?php if (requirePagePermission()) : ?>
    <div class="container-fluid flex-grow-1 container-p-y">

        <h4 class="fw-bold py-3 mb-4">
            <?= showPageTitle() ?>
        </h4>

        <div class="col-lg-12 order-2 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <!-- FILTER -->
                    <div class="row">
                        <div class="col-xl-12 mb-4">
                            <button type="button" class="btn btn-warning btn-sm float-end" onclick="getDataList()" title="Refresh">
                                <i class='bx bx-refresh'></i>
                            </button>
                            <button type="button" class="btn btn-info btn-sm float-end me-2" onclick="addRoles()" title="Add New Role">
                                <i class='bx bx-plus'></i> Add New Role
                            </button>
                            <button type="button" class="btn btn-primary btn-sm float-end me-2" onclick="showPermission()" title="List Permissions">
                                <i class='bx bx-shield-quarter'></i> Abilities
                            </button>
                            <select id="filter_role_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All </option>
                                <option value="1"> Active </option>
                                <option value="0"> Inactive </option>
                            </select>
                        </div>
                    </div>

                    <!-- DATATABLE -->
                    <div id="bodyDiv" class="row">
                        <div class="col-xl-12 mb-4">
                            <div id="nodataDiv" style="display: none;"> <?= nodata() ?> </div>
                            <div id="dataListDiv" class="table-responsive" style="display: block;">
                                <table id="dataList" class="table table-responsive table-hover table-striped table-bordered collapsed nowrap" width="100%">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="color:white"> Name </th>
                                            <th style="color:white"> Rank </th>
                                            <th style="color:white"> Count </th>
                                            <th style="color:white"> Status </th>
                                            <th style="color:white"> # </th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <script type="text/javascript">
        $(document).ready(async function() {
            await getDataList();
        });

        // GET DATATABLE (SERVER-SIDE)
        async function getDataList() {
            generateDatatableServer('dataList', 'controllers/RoleController.php', 'nodataDiv', {
                    'action': 'listRolesDatatable',
                    'role_status': $("#filter_role_status").val()
                },
                [{
                        "data": "name",
                        // "width": "60%",
                        "targets": 0
                    },
                    {
                        "data": "rank",
                        "width": "10%",
                        "targets": 1
                    },
                    {
                        "data": "count",
                        "width": "10%",
                        "targets": 2
                    },
                    {
                        "data": "status",
                        "width": "5%",
                        "targets": 3
                    },
                    {
                        // Action
                        "data": "action",
                        "render": function(data, type, row) {
                            return data;
                        },
                        "targets": -1,
                        "width": "3%",
                        "searchable": false,
                        "orderable": false
                    }
                ], 'bodyDiv');
        }

        function addRoles() {
            loadFormContent('views/rbac/_roleForm.php', 'rolesForm', '500px', 'controllers/RoleController.php', 'Add Roles', {}, 'offcanvas');
        }

        async function editRecord(id) {
            const res = await callApi('post', "controllers/RoleController.php", {
                'action': 'show',
                'id': id
            });

            if (isSuccess(res)) {
                loadFormContent('views/rbac/_roleForm.php', 'rolesForm', '500px', 'controllers/RoleController.php', 'Update Roles', res.data.data, 'offcanvas');
            }
        }

        async function deleteRecord(id) {
            Swal.fire({
                title: 'Are you sure?',
                html: 'You won\'t be able to revert this action!<br><strong>This item will be permanently deleted.</strong>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Remove it!',
                reverseButtons: true,
                customClass: {
                    container: 'swal2-customCss'
                },
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const res = await callApi('post', "controllers/RoleController.php", {
                        'action': 'destroy',
                        'id': id
                    });

                    if (isSuccess(res)) {
                        const response = res.data;
                        noti(response.code, response.message);

                        getDataList(); // reload
                    }
                }
            })
        }

        async function permissionRecord(id, roleName) {
            loadFileContent('views/rbac/_permissionAssignForm.php', 'generalContent', '1000px', `Permission Assignment : ${roleName}`, {
                'id': id,
                'name': roleName,
            }, 'offcanvas');
        }

        async function showPermission() {
            loadFileContent('views/rbac/_permissionListView.php', 'generalContent', '1200px', 'List Abilities', [], 'offcanvas');
        }
    </script>
<?php endif; ?>

<?php includeTemplate('footer'); ?>