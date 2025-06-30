<?php
$loginRequired = true;
$titlePage = "List Email Template";
$currentPage = 'rbac';
$currentSubPage = 'email';
$permission = ''; // All users can access this page if not set
include_once __DIR__ . '/../_templates/header.php';
?>

<?php if (permission($permission ?? null)) { ?>
    <div class="container-fluid flex-grow-1 container-p-y">

        <h4 class="fw-bold py-3 mb-4">
            <span class="text-muted fw-light"> Management /</span> <?= $titlePage ?>
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
                            <button type="button" class="btn btn-info btn-sm float-end me-2" onclick="emailTemplateForm()" title="Refresh">
                                <i class='bx bx-plus'></i> Add New Email Template
                            </button>
                            <select id="filter_email_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
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
                                            <th style="color:white"> Email Type </th>
                                            <th style="color:white"> Subject </th>
                                            <th style="color:white"> CC </th>
                                            <th style="color:white"> BCC </th>
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
            generateDatatableServer('dataList', 'controllers/MasterEmailTemplateController.php', 'nodataDiv', {
                    'action': 'listEmailTemplateDatatable',
                    'email_status': $("#filter_email_status").val()
                },
                [{
                        "data": "type",
                        "width": "22%",
                        "targets": 0
                    },
                    {
                        "data": "subject",
                        // "width": "50%",
                        "targets": 1
                    },
                    {
                        "data": "cc",
                        "width": "6%",
                        "targets": 2
                    },
                    {
                        "data": "bcc",
                        "width": "6%",
                        "targets": 3
                    },
                    {
                        "data": "status",
                        "width": "5%",
                        "targets": 4
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

        function emailTemplateForm(type = 'create', data = null) {
            const modalTitle = (type == 'create') ? 'REGISTER EMAIL TEMPLATE' : 'UPDATE EMAIL TEMPLATE';
            loadFileContent('views/rbac/_emailTemplateForm.php', 'generalContent', 'fullscreen', modalTitle, data);
        }

        async function editRecord(id) {
            const res = await callApi('post', "controllers/MasterEmailTemplateController.php", {
                'action': 'show',
                'id': id
            });

            if (isSuccess(res)) {
                emailTemplateForm('update', res.data.data);
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
                    const res = await callApi('post', "controllers/MasterEmailTemplateController.php", {
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
    </script>
<?php } else {
    show_403();
} ?>

<?php include_once __DIR__ . '/../_templates/footer.php' ?>