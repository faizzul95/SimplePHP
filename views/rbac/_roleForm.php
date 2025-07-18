<form id="rolesForm" method="POST" class="mt-0">

    <div class="row">
        <div class="col-12">
            <label class="form-label"> Name <span class="text-danger">*</span> </label>
            <input type="text" id="role_name" name="role_name" class="form-control" onkeyup="this.value = this.value.toUpperCase();" autocomplete="off" required>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <label class="form-label"> Rank <span class="text-danger">*</span> </label>
            <input type="text" id="role_rank" name="role_rank" class="form-control" autocomplete="off" required>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <label class="form-label"> Status <span class="text-danger">*</span> </label>
            <select id="role_status" name="role_status" class="form-control" required>
                <option value=""> - Select - </option>
                <option value="0"> Inactive </option>
                <option value="1"> Active </option>
            </select>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-lg-12">
            <span class="text-danger">* Indicates a required field</span>
            <input type="hidden" id="id" name="id" placeholder="id">
        </div>
    </div>

    <input type="hidden" name="action" value="save" readonly>
    <button id="submitBtn" type="submit" class="btn btn-md btn-info mb-3" style="position: absolute;bottom: 0;"> <i class='bx bx-save'></i> Save </button>
</form>

<script>
    async function getPassData(baseUrl, data) {
        // console.log('form : ', data);
    }

    $("#rolesForm").submit(function(event) {
        event.preventDefault();

        if (validateDataRole()) {

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
                        const res = await submitApi(url, form.serializeArray(), 'rolesForm');
                        if (isSuccess(res)) {

                            if (isSuccess(res.data.code)) {
                                noti(res.status, res.data.message);
                                getDataList();
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

    function validateDataRole() {

        const rules = {
            'role_name': 'required|min_length:3|max_length:64',
            'role_rank': 'required|integer|min:1',
            'id': 'integer',
        };

        const message = {
            'role_name': 'Role Name',
            'role_rank': 'Role Rank',
            'id': 'ID',
        };

        return validationJs(rules, message);
    }
</script>