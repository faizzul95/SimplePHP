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
                            
                            @can('user-create')
                            <button type="button" class="btn btn-info btn-sm float-end me-2" onclick="addUser()" title="Add New User">
                                <i class='bx bx-plus'></i> Add New User
                            </button>
                            @endcan

                            <select id="filter_user_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All Status </option>
                                <option value="1"> Active </option>
                                <option value="0"> Inactive </option>
                                <option value="2"> Banned </option>
                                <option value="4"> Unverified </option>
                            </select>

                            <!-- <select id="filter_deleted_user" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All User </option>
                                <option value="1"> Deleted User </option>
                            </select> -->

                            <select id="filter_gender_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All Gender </option>
                                <option value="1"> Male </option>
                                <option value="2"> Female </option>
                            </select>

                            <select id="filter_profile" class="form-control form-control-sm me-2 float-end" style="width: 180px;" onchange="getDataList()">
                                <option value=""> All Profiles </option>
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
                                            <th style="color:white"> Avatar </th>
                                            <th style="color:white"> Name </th>
                                            <th style="color:white"> Contact Information </th>
                                            <th style="color:white"> Gender </th>
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
        let usersTableManager = null;

        $(document).ready(async function() {
            await getProfileList('filter_profile');
            await getDataList();
        });

        async function getProfileList(id, includeAll = true) {
            const res = await callApi('post', "{{ route('roles.options') }}", {});

            if (isSuccess(res)) {

                const data = res.data.data;

                $("#" + id).empty();

                if (includeAll) {
                    $("#" + id).append('<option value=""> All Profiles </option>');
                    $("#" + id).append('<option value="N/A"> <i> (No Profile) </i> </option>');
                } else {
                    $("#" + id).append('<option value=""> - Select - </option>');
                }

                data.forEach(function(item) {
                    $("#" + id).append('<option value="' + item.id + '">' + item.role_name + '</option>');
                });
            }
        }

        async function getDataList(resetPaging = false) {
            const tableConfig = {
                tableId: 'dataList',
                mode: 'server',
                rowId: 'row_key',
                ajax: {
                    url: '{{ route("users.list") }}',
                    method: 'POST',
                    data: function() {
                        return {
                            user_status_filter: $("#filter_user_status").val(),
                            user_gender_filter: $("#filter_gender_status").val(),
                            user_profile_filter: $("#filter_profile").val(),
                            user_deleted_filter: $("#filter_deleted_user").val()
                        };
                    }
                },
                columns: [
                    { data: 'avatar', width: '5%', targets: 0 },
                    { data: 'name', targets: 1 },
                    { data: 'contact', width: '35%', targets: 2 },
                    { data: 'gender', width: '8%', targets: 3 },
                    { data: 'status', width: '7%', targets: 4 },
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
                    useLoadingIndicator: true,
                    renderEmptyState: function() {
                        return nodata();
                    }
                },
                mutation: {
                    rowPath: null,
                    shouldKeepRow: function(rowData) {
                        const statusFilter = $("#filter_user_status").val();
                        const genderFilter = $("#filter_gender_status").val();
                        const profileFilter = $("#filter_profile").val();

                        if (statusFilter !== '' && String(rowData.user_status_value) !== String(statusFilter)) {
                            return false;
                        }

                        if (genderFilter !== '' && String(rowData.user_gender_value) !== String(genderFilter)) {
                            return false;
                        }

                        if (profileFilter === '') {
                            return true;
                        }

                        if (profileFilter === 'N/A') {
                            return !rowData.has_profile;
                        }

                        return Array.isArray(rowData.profile_role_ids)
                            && rowData.profile_role_ids.map(String).includes(String(profileFilter));
                    }
                }
            };

            if (!usersTableManager || !usersTableManager.instance) {
                usersTableManager = datatableManager('dataList', tableConfig);
                return usersTableManager.create(tableConfig);
            }

            usersTableManager = datatableManager('dataList', tableConfig);
            usersTableManager.reload(resetPaging);
            return usersTableManager.instance;
        }

        function addUser() {
            modalManager().showFormContent({
                fileName: 'views/directory/_userForm.php',
                overlayType: 'offcanvas',
                size: '550px',
                formAction: '{{ route("users.save") }}',
                title: 'Add User',
                dataArray: {}
            });
        }

        async function editRecord(id) {
            const res = await callApi('get', "{{ route('users.show') }}".replace('{id}', id));

            if (isSuccess(res)) {
                await modalManager().showFormContent({
                    fileName: 'views/directory/_userForm.php',
                    overlayType: 'offcanvas',
                    size: '550px',
                    formAction: '{{ route("users.save") }}',
                    title: 'Update User',
                    dataArray: res.data.data
                });
            }
        }

        async function deleteRecord(id, rowKey = null) {
            await confirmDeleteAction({
                url: "{{ route('users.delete') }}".replace('{id}', id),
                onSuccess: function() {
                    removeDatatableRow('dataList', rowKey);
                }
            });
        }

        async function resetPassword(id) {
            await confirmApiAction({
                html: 'This will reset the user\'s password. The user will need to change their password upon next login.<br><br><strong>Do you want to continue?</strong>',
                confirmButtonText: 'Yes, Reset NOW!',
                method: 'post',
                url: "{{ route('auth.reset-password') }}",
                data: {
                    id: id
                }
            });
        }
    </script>
@endpush