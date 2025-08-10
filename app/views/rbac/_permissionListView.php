<div class="row">
    <div class="col-xl-12 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Abilities Form</h4>
        </div>
    </div>

    <div class="col-xl-12 mb-4">
        <form id="permissionForm" method="post" action="controllers/PermissionController.php">
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
                <input type="hidden" name="action" value="saveAbilities">
                <button id="submitBtn" type="submit" class="btn btn-md btn-info mb-3"> <i class='bx bx-save'></i> Save </button>
            </center>
        </form>

        <div class="row mt-2">
            <div class="col-lg-12">
                <span class="text-danger">* Indicates a required field</span>
            </div>
        </div>
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
        <div id="nodataPermDiv" style="display: none;"> <?= nodata() ?> </div>
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
    async function getPassData(baseUrl, data) {
        await getListPermission();
    }

    // GET DATATABLE (SERVER-SIDE)
    async function getListPermission() {
        generateDatatableServer('dataListPerm', 'controllers/PermissionController.php', 'nodataPermDiv', {
                'action': 'listPermissionDatatable',
            },
            [{
                    "data": "name",
                    "width": "20%",
                    "targets": 0
                },
                {
                    "data": "slug",
                    "width": "15%",
                    "targets": 1
                },
                {
                    "data": "desc",
                    // "width": "10%",
                    "targets": 2
                },
                {
                    "data": "count",
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
            ], 'bodyPermDiv');
    }

    async function editPermRecord(id) {
        const res = await callApi('post', "controllers/PermissionController.php", {
            'action': 'show',
            'id': id
        });

        if (isSuccess(res)) {
            const response = res.data;
            $('#perm_id').val(response.data.id);
            $('#abilities_name').val(response.data.abilities_name);
            $('#abilities_slug').val(response.data.abilities_slug);
            $('#abilities_desc').val(response.data.abilities_desc);
        }
    }

    async function deletePermRecord(id) {
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
                const res = await callApi('post', "controllers/PermissionController.php", {
                    'action': 'destroy',
                    'id': id
                });

                if (isSuccess(res)) {
                    const response = res.data;
                    noti(response.code, response.message);

                    getListPermission(); // reload
                }
            }
        })
    }

    $("#permissionForm").submit(function(event) {
        event.preventDefault();

        if (validateDataPerm(this)) {

            const form = $(this);
            const url = form.attr('action');

            Swal.fire({
                title: 'Are you sure?',
                html: "Form will be submitted!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Confirm!',
                reverseButtons: true,
                customClass: {
                    container: 'swal2-customCss'
                },
            }).then(
                async (result) => {
                    if (result.isConfirmed) {
                        const res = await submitApi(url, form.serializeArray(), 'permissionForm');
                        if (isSuccess(res)) {

                            if (isSuccess(res.data.code)) {
                                noti(res.status, res.data.message);
                                getListPermission();

                                // reset form
                                $('#perm_id').val('');
                                $('#abilities_name').val('');
                                $('#abilities_slug').val('');
                                $('#abilities_desc').val('');
                            } else {
                                noti(400, res.data.message)
                            }

                        }
                    }
                })

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