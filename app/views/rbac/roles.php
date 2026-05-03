@extends('_templates.layouts.app')

@section('content')
    <div class="container-fluid flex-grow-1 container-p-y">

        <h4 class="fw-bold py-3 mb-4">
            {!! showPageTitle() !!}
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
                            @can('rbac-roles-create')
                            <button type="button" class="btn btn-info btn-sm float-end me-2" onclick="addRoles()" title="Add New Role">
                                <i class='bx bx-plus'></i> Add New Role
                            </button>
                            @endcan
                            @can('rbac-abilities-view')
                            <button type="button" class="btn btn-primary btn-sm float-end me-2" onclick="showPermission()" title="List Permissions">
                                <i class='bx bx-shield-quarter'></i> Abilities
                            </button>
                            @endcan
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
                            <div id="nodataDiv" style="display: none;"> {!! nodata() !!} </div>
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
@endsection

@push('scripts')
    <script type="text/javascript">
        let rolesTableManager = null;

        $(document).ready(async function() {
            await getDataList();
        });

        async function getDataList(resetPaging = false) {
            const tableConfig = {
                tableId: 'dataList',
                mode: 'server',
                rowId: 'row_key',
                ajax: {
                    url: '{{ route("roles.list") }}',
                    method: 'POST',
                    data: function() {
                        return {
                            role_status: $("#filter_role_status").val()
                        };
                    }
                },
                columns: [
                    { data: 'name', targets: 0 },
                    { data: 'rank', width: '10%', targets: 1 },
                    { data: 'count', width: '10%', targets: 2 },
                    { data: 'status', width: '5%', targets: 3 },
                    {
                        data: 'action',
                        render: function(data) {
                            return data;
                        },
                        targets: -1,
                        width: '3%',
                        searchable: false,
                        orderable: false
                    }
                ],
                ui: {
                    emptyStateContainerId: 'nodataDiv',
                    loadingContainerId: 'bodyDiv',
                    showSkeleton: true,
                    useLoadingIndicator: true,
                    renderEmptyState: function() {
                        return nodata();
                    }
                },
                mutation: {
                    rowPath: null,
                    shouldKeepRow: function(rowData) {
                        const filterValue = $("#filter_role_status").val();

                        if (filterValue === '') {
                            return true;
                        }

                        return String(rowData.role_status_value) === String(filterValue);
                    }
                }
            };

            if (!rolesTableManager || !rolesTableManager.instance) {
                rolesTableManager = datatableManager('dataList', tableConfig);
                return rolesTableManager.create(tableConfig);
            }

            rolesTableManager = datatableManager('dataList', tableConfig);
            rolesTableManager.reload(resetPaging);
            return rolesTableManager.instance;
        }

        function addRoles() {
            modalManager().showFormContent({
                fileName: 'views/rbac/_roleForm.php',
                overlayType: 'offcanvas',
                size: '500px',
                formAction: '{{ route("roles.save") }}',
                title: 'Add Roles',
                dataArray: {}
            });
        }

        async function editRecord(key) {
            const res = await callApi('get', "{{ route('roles.show') }}".replace('{id}', key));

            if (isSuccess(res)) {
                modalManager().showFormContent({
                    fileName: 'views/rbac/_roleForm.php',
                    overlayType: 'offcanvas',
                    size: '500px',
                    formAction: '{{ route("roles.save") }}',
                    title: 'Update Roles',
                    dataArray: res.data.data
                });
            }
        }

        async function deleteRecord(id, rowKey = null) {
            await confirmDeleteAction({
                url: "{{ route('roles.delete') }}".replace('{id}', id),
                onSuccess: function() {
                    removeDatatableRow('dataList', rowKey);
                }
            });
        }

        async function permissionRecord(key, roleName) {
            modalManager().showFileContent({
                fileName: 'views/rbac/_permissionAssignForm.php',
                overlayType: 'offcanvas',
                size: '1000px',
                title: `Permission Assignment : ${roleName}`,
                dataArray: { 'id': key, 'name': roleName }
            });
        }

        async function showPermission() {
            modalManager().showFileContent({
                fileName: 'views/rbac/_permissionListView.php',
                overlayType: 'offcanvas',
                size: '1200px',
                title: 'List Abilities',
                dataArray: []
            });
        }
    </script>
@endpush
