<form id="userForm" method="POST" class="mt-0">

    <div class="row">
        <div class="col-12">
            <label class="form-label"> Full Name <span class="text-danger">*</span> </label>
            <input type="text" id="name" name="name" class="form-control" onkeyup="this.value = this.value.toUpperCase();" autocomplete="off">
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <label class="form-label"> Preffered Name <span class="text-danger">*</span> </label>
            <input type="text" id="user_preferred_name" name="user_preferred_name" class="form-control" autocomplete="off">
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <label class="form-label"> Email <span class="text-danger">*</span> </label>
            <input type="email" id="email" name="email" class="form-control" autocomplete="off">
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <label class="form-label"> Gender <span class="text-danger">*</span> </label>
            <select id="user_gender" name="user_gender" class="form-control">
                <option value="1"> Male </option>
                <option value="2"> Female </option>
            </select>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <label class="form-label"> Status <span class="text-danger">*</span> </label>
            <select id="user_status" name="user_status" class="form-control">
                <option value="0"> Inactive </option>
                <option value="1"> Active </option>
                <option value="2"> Banned/Suspended </option>
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
        console.log('form : ', data);
    }

    $("#userForm").submit(function(event) {
        event.preventDefault();

        if (validateDataCart()) {

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
                        const res = await submitApi(url, form.serializeArray(), 'userForm');
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

    function validateDataCart() {

        const rules = {
            'name': 'required|min:3|max:255',
            'user_preferred_name': 'required|min:3|max:255',
            'email': 'required|email|max_length:255',
            'id': 'integer',
        };

        const message = {
            'name': 'Full Name',
            'user_preferred_name': 'Preffered Rank',
            'email': 'Email',
            'id': 'ID',
        };

        return validationJs(rules, message);
    }
</script>