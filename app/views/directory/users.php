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

        const USER_STATUS_BADGES = {
            0: '<span class="badge bg-label-warning"> Inactive </span>',
            1: '<span class="badge bg-label-success"> Active </span>',
            2: '<span class="badge bg-label-warning"> Suspended </span>',
            3: '<span class="badge bg-label-danger"> Deleted </span>',
            4: '<span class="badge bg-label-dark"> Unverified </span>',
        };

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
                    {
                        data: null,
                        render: function(data, type, row) {
                            const defaultImg = "{{ asset('upload/default.jpg') }}";
                            let html = `<div class="avatar-lg" style="position: relative; display:inline-block;">
                                <img alt="user image" class="img-fluid img-thumbnail rounded-circle" loading="lazy"
                                     src="${row.avatar_url}" onerror="this.onerror=null;this.src='${defaultImg}';">`;

                            if (row.can_upload_avatar) {
                                const uploadFunc = `updateCropperPhoto('PROFILE UPLOAD', '${row.avatar_id}', '${row.key}', 'USER_PROFILE', 'users', '${row.avatar_original_url}', 'getDataList', 'directory', 'avatar')`;
                                html += `<a class="btn btn-icon btn-info btn-xs rounded-circle" href="javascript:void(0)"
                                            onclick="${uploadFunc}" style="position: absolute; top: 40px; right: -6px;" title="Change profile">
                                            <i aria-hidden="true" class="tf-icons bx bx-camera" style="font-size: 0.75rem; position: relative; top: 45%; transform: translateY(-50%);"></i>
                                         </a>`;
                            }

                            html += `</div>`;
                            return html;
                        },
                        width: '5%',
                        targets: 0,
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'name',
                        render: function(data, type, row) {
                            // name/profile_role_names are already HTML-escaped server-side via ->safeOutput() in UserController
                            let html = row.name;
                            if (row.profile_role_names && row.profile_role_names.length > 0) {
                                html += ` <span class="text-muted"><i><small>(${row.profile_role_names.join(', ')})</i></small></span>`;
                            }
                            return html;
                        },
                        targets: 1
                    },
                    {
                        data: null,
                        render: function(data, type, row) {
                            // email/user_contact_no are already HTML-escaped server-side via ->safeOutput() in UserController
                            const contact = row.user_contact_no
                                ? `Contact No : ${row.user_contact_no}`
                                : 'Contact No : <small><i> (No information provided) </i></small>';
                            return `<ul><li>Email : ${row.email}</li><li>${contact}</li></ul>`;
                        },
                        width: '35%',
                        targets: 2,
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'user_gender',
                        render: function(data) { return data === 1 ? 'Male' : 'Female'; },
                        width: '8%',
                        targets: 3
                    },
                    {
                        data: null,
                        render: function(data, type, row) {
                            if (row.deleted_at) return USER_STATUS_BADGES[3];
                            return USER_STATUS_BADGES[row.user_status] ?? '<span class="badge bg-label-danger"> Unknown Status </span>';
                        },
                        width: '7%',
                        targets: 4,
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: null,
                        render: function(data, type, row) {
                            const key = row.key;
                            const rowKey = row.row_key;

                            if (row.deleted_at) {
                                return `<a href="javascript:void(0);" onclick="restoreRecord('${key}')" title="Restore users">
                                            <i class="bx bx-refresh"></i>
                                        </a>`;
                            }

                            let updateAction = '';
                            let dropdownAction = '';

                            if (row.can_update) {
                                updateAction = `<span style="display: inline-block; vertical-align: middle;">
                                    <i class="bx bx-edit-alt" style="cursor: pointer;" onclick="editRecord('${key}')" title="Edit"></i>
                                </span>`;
                            }

                            if (!row.is_superadmin) {
                                let deleteAction = row.can_delete
                                    ? `<a href="javascript:void(0);" onclick="deleteRecord('${key}', '${rowKey}')" class="dropdown-item">
                                           <i class="bx bx-trash me-1"></i> Delete
                                       </a>`
                                    : '';
                                let resetAction = row.can_update
                                    ? `<a href="javascript:void(0);" onclick="resetPassword('${key}')" class="dropdown-item">
                                           <i class="bx bx-key me-1"></i> Reset Password
                                       </a>`
                                    : '';

                                dropdownAction = `<div class="dropdown" style="display: inline-block; vertical-align: middle;">
                                    <button type="button" class="btn p-0 dropdown-toggle hide-arrow" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
                                        <i class="bx bx-dots-vertical-rounded"></i>
                                    </button>
                                    <div class="dropdown-menu">${deleteAction}${resetAction}</div>
                                </div>`;
                            }

                            return `${updateAction} ${dropdownAction}`;
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

        async function restoreRecord(id) {
            await confirmApiAction({
                html: 'This will restore the deleted user.<br><br><strong>Do you want to continue?</strong>',
                confirmButtonText: 'Yes, Restore!',
                method: 'post',
                url: "{{ route('users.restore') }}".replace('{id}', id),
                onSuccess: function() {
                    getDataList(true);
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