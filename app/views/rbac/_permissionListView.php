<div class="row">
    <div class="col-xl-12 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Abilities Form</h4>
        </div>
    </div>

    <div class="col-xl-12 mb-4">
        <?php $canManageAbilities = permission('rbac-abilities-create') || permission('rbac-abilities-update'); ?>
        @if($canManageAbilities)
        <form id="permissionForm" method="post" action="{{ route('permissions.save') }}">
            <div class="row">
                <div class="col-xl-8 mb-3">
                    <label for="abilities_name" class="form-label"> Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="abilities_name" name="abilities_name" maxlength="50" autocomplete="off" required>
                </div>
                <div class="col-xl-4 mb-3">
                    <label for="abilities_slug" class="form-label"> Slug <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="abilities_slug" name="abilities_slug" maxlength="100" autocomplete="off" required>
                </div>
            </div>

            <div class="row">
                <div class="col-xl-12 mb-3">
                    <label for="abilities_desc" class="form-label"> Description </label>
                    <input type="text" class="form-control" id="abilities_desc" name="abilities_desc" maxlength="255" autocomplete="off">
                </div>
            </div>

            <center>
                <input type="hidden" id="perm_id" name="id">
                <button id="submitBtn" type="submit" class="btn btn-md btn-info mb-3"> <i class='bx bx-save'></i> Save </button>
            </center>
        </form>

        <div class="row mt-2">
            <div class="col-lg-12">
                <span class="text-danger">* Indicates a required field</span>
            </div>
        </div>
        @else
        <div class="alert alert-info mb-0" role="alert">
            You have read-only access to abilities.
        </div>
        @endif
    </div>
</div>

<hr>

<div class="row">
    <div class="col-xl-12 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Abilities List</h4>
        </div>
    </div>
</div>

<div id="bodyPermDiv" class="row mt-4">
    <div class="col-xl-12 mb-4">
        <div id="nodataPermDiv" style="display: none;"> {!! nodata() !!} </div>
        <div id="dataListPermDiv" class="table-responsive" style="display: block;">
            <table id="dataListPerm" class="table table-responsive table-hover table-striped table-bordered collapsed nowrap" width="100%">
                <thead class="table-dark">
                    <tr>
                        <th style="color:white"> Name </th>
                        <th style="color:white"> Slug </th>
                        <th style="color:white"> Description </th>
                        <th style="color:white"> Count </th>
                        <th style="color:white"> # </th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
    window.__permissionsTableManager = window.__permissionsTableManager || null;

    async function getPassData(baseUrl, data) {
        await getListPermission();
    }

    function setPermissionFormValues(data = {}) {
        $('#perm_id').val(data.id ?? '');
        $('#abilities_name').val(data.abilities_name ?? '');
        $('#abilities_slug').val(data.abilities_slug ?? '');
        $('#abilities_desc').val(data.abilities_desc ?? '');
    }

    async function getListPermission(resetPaging = false) {
        let permissionsTableManager = window.__permissionsTableManager;
        const currentTableElement = document.getElementById('dataListPerm');
        if (permissionsTableManager && permissionsTableManager.instance) {
            const activeTableElement = typeof permissionsTableManager.instance.table === 'function'
                ? permissionsTableManager.instance.table().node()
                : null;

            if (!activeTableElement || !document.body.contains(activeTableElement) || activeTableElement !== currentTableElement) {
                permissionsTableManager.destroy();
                permissionsTableManager = null;
                window.__permissionsTableManager = null;
            }
        }

        const tableConfig = {
            tableId: 'dataListPerm',
            mode: 'server',
            rowId: 'row_key',
            ajax: {
                url: '{{ route("permissions.list") }}',
                method: 'POST',
                data: function() {
                    return {};
                }
            },
            columns: [
                { data: 'name', width: '20%', targets: 0 },
                { data: 'slug', width: '15%', targets: 1 },
                { data: 'desc', targets: 2 },
                { data: 'count', width: '5%', targets: 3},
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
                emptyStateContainerId: 'nodataPermDiv',
                loadingContainerId: 'bodyPermDiv',
                showSkeleton: true,
                useLoadingIndicator: true,
                renderEmptyState: function() {
                    return nodata();
                }
            },
            mutation: {
                rowPath: null
            }
        };

        if (!permissionsTableManager || !permissionsTableManager.instance) {
            permissionsTableManager = datatableManager('dataListPerm', tableConfig);
            window.__permissionsTableManager = permissionsTableManager;
            return permissionsTableManager.create(tableConfig);
        }

        permissionsTableManager = datatableManager('dataListPerm', tableConfig);
        window.__permissionsTableManager = permissionsTableManager;
        permissionsTableManager.reload(resetPaging);
        return permissionsTableManager.instance;
    }

    async function editPermRecord(id) {
        const res = await callApi('get', "{{ route('permissions.show') }}".replace('{id}', id));

        if (isSuccess(res)) {
            setPermissionFormValues(res.data.data ?? {});
        }
    }

    async function deletePermRecord(id, rowKey = null) {
        await confirmDeleteAction({
            url: "{{ route('permissions.delete') }}".replace('{id}', id),
            onSuccess: function() {
                removeDatatableRow('dataListPerm', rowKey);
            }
        });
    }

    $("#permissionForm").off('submit.permissionForm').on('submit.permissionForm', function(event) {
        event.preventDefault();

        if (validateDataPerm(this)) {

            const form = $(this);
            const url = form.attr('action');

            confirmSubmitAction({
                onConfirm: async function() {
                    const res = await submitApi(url, form.serializeArray(), 'permissionForm', null, false);
                    if (isSuccess(res)) {

                        if (isSuccess(res.data.code)) {
                            noti(res.status, res.data.message);
                            syncDatatableRow('dataListPerm', res.data.data ?? null);
                            setPermissionFormValues();
                        } else {
                            noti(400, res.data.message)
                        }

                    }
                }
            });

        } else {
            validationJsError('toastr', 'single'); // single or multi
        }
    });

    function validateDataPerm(formObj) {

        const rules = {
            'abilities_name' : 'required|min_length:5|max_length:50',
            'abilities_slug' : 'required|min_length:1|max_length:100',
            'abilities_desc' : 'max_length:255',
            'id': 'integer',
        };

        const message = {
            'abilities_name': {
                label: 'Name'
            },
            'abilities_slug': {
                label: 'Slug'
            },
            'abilities_desc': {
                label: 'Description'
            }
        };

        return validationJs(formObj, rules, message);
    }
</script>