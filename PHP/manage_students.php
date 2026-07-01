<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $name = trim($_POST['student_name']);
    $phone = trim($_POST['phone']);
    if (!empty($name) && !empty($phone)) {
        $stmt = $db->prepare("INSERT INTO students (student_name, phone) VALUES (?, ?)");
        $stmt->execute([$name, $phone]);
        $message = "<p class='success'>Đã thêm học viên mới thành công!</p>";
    } else {
        $message = "<p class='error'>Vui lòng nhập đầy đủ tên và SĐT!</p>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_class'])) {
    $studentId = (int)$_POST['student_id'];
    $classId = (int)$_POST['class_id'];
    if ($studentId > 0 && $classId > 0) {
        try {
            $stmt = $db->prepare("INSERT INTO student_class (student_id, class_id) VALUES (?, ?)");
            $stmt->execute([$studentId, $classId]);
            $message = "<p class='success'>Đã ghi danh học viên vào lớp thành công!</p>";
        } catch (Exception $e) {
            $message = "<p class='error'>Học viên này đã được thêm vào lớp này trước đó rồi!</p>";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $id = (int)$_POST['edit_student_id'];
    $name = trim($_POST['edit_student_name']);
    $phone = trim($_POST['edit_phone']);
    if ($id > 0 && !empty($name) && !empty($phone)) {
        $stmt = $db->prepare("UPDATE students SET student_name = ?, phone = ? WHERE id = ?");
        $stmt->execute([$name, $phone, $id]);
        $message = "<p class='success'>Đã cập nhật thông tin học viên thành công!</p>";
    }
}

if (isset($_GET['delete_student_id'])) {
    $db->prepare("DELETE FROM students WHERE id = ?")->execute([(int)$_GET['delete_student_id']]);
    header('Location: manage_students.php');
    exit;
}

$students = $db->query("SELECT * FROM students ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT * FROM classes WHERE status = 'Active' ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$joinedClassRows = $db->query("
    SELECT sc.student_id, c.class_name
    FROM student_class sc
    JOIN classes c ON c.id = sc.class_id
    ORDER BY c.class_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$joinedClassesByStudent = [];
foreach ($joinedClassRows as $row) {
    $joinedClassesByStudent[(int)$row['student_id']][] = $row['class_name'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản Lý Học Viên</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
    <style>
        .action-header-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
        .student-action-links { display: flex; gap: 8px; justify-content: center; }
        .custom-modal { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 16px; }
        .custom-modal-content { background: white; width: min(460px, 100%); border-radius: var(--radius-md); padding: 24px; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; }
        .modal-close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); }
        .search-box-container { background: white; border: 1px solid var(--border-color); padding: 14px 20px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .search-input { flex: 1; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.95rem; }
        .no-results { text-align: center; padding: 30px; color: var(--text-muted); font-style: italic; background: white; border-radius: var(--radius-md); border: 1px solid var(--border-color); }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Hệ Thống Quản Lý Học Viên</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Quản lý danh sách học viên, chỉnh sửa thông tin hoặc phân bổ vào lớp học nhanh</span>
            </div>
        </div>

        <?= $message ?>

        <div class="action-header-bar">
            <button class="btn" onclick="openModal('addStudentModal')" style="padding: 12px 20px; font-weight:600;">+ Thêm Học Viên Mới</button>
            <button class="btn" onclick="openModal('assignClassModal')" style="background: #0f766e; padding: 12px 20px; font-weight:600;">Xếp Lớp Cho Học Viên</button>
        </div>

        <div id="addStudentModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h3 style="margin:0;">Thêm học viên mới</h3>
                    <button type="button" class="modal-close-btn" onclick="closeModal('addStudentModal')">&times;</button>
                </div>
                <form method="POST" class="form-group" style="margin-bottom:0;">
                    <div style="margin-bottom: 12px;">
                        <label>Họ và tên học viên:</label>
                        <input type="text" name="student_name" placeholder="Ví dụ: Nguyễn Văn A" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label>Số điện thoại:</label>
                        <input type="text" name="phone" placeholder="Ví dụ: 0987654321" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                    </div>
                    <button type="submit" name="add_student" class="btn" style="width:100%;">+ Tạo học viên mới</button>
                </form>
            </div>
        </div>

        <div id="assignClassModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h3 style="margin:0;">Ghi Danh Vào Lớp Học</h3>
                    <button class="modal-close-btn" onclick="closeModal('assignClassModal')">&times;</button>
                </div>
                <form method="POST" class="form-group" style="margin-bottom:0;">
                    <div style="margin-bottom: 12px;">
                        <label>Chọn học viên:</label>
                        <select name="student_id" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                            <option value="">-- Chọn học viên --</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['student_name']) ?> (<?= htmlspecialchars($s['phone']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label>Chọn lớp gán vào:</label>
                        <select name="class_id" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                            <option value="">-- Chọn lớp học --</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="assign_class" class="btn" style="width:100%; background:#0f766e;">Xác nhận xếp lớp</button>
                </form>
            </div>
        </div>

        <div id="editStudentModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h3 style="margin:0;">Chỉnh sửa thông tin</h3>
                    <button type="button" class="modal-close-btn" onclick="closeModal('editStudentModal')">&times;</button>
                </div>
                <form method="POST" class="form-group" style="margin-bottom:0;">
                    <input type="hidden" name="edit_student_id" id="editModalStudentId">
                    <div style="margin-bottom: 12px;">
                        <label>Họ và tên học viên:</label>
                        <input type="text" name="edit_student_name" id="editModalStudentName" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label>Số điện thoại:</label>
                        <input type="text" name="edit_phone" id="editModalPhone" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                    </div>
                    <button type="submit" name="edit_student" class="btn" style="width:100%;">Lưu thay đổi</button>
                </form>
            </div>
        </div>

        <div class="search-box-container">
            <span style="font-size: 1.1rem;">🔍</span>
            <input type="text" id="studentSearchInput" class="search-input" placeholder="Nhập tên học viên hoặc số điện thoại cần tìm nhanh...">
            <button type="button" class="btn-delete" id="clearSearchBtn" style="padding: 8px 12px; display: none;">Xóa tìm kiếm</button>
        </div>

        <div class="table-responsive">
            <table class="admin-table" style="width:100%;" id="studentTable">
                <thead>
                    <tr>
                        <th style="padding:12px; width:80px;">ID</th>
                        <th style="padding:12px;">Tên Học Viên</th>
                        <th style="padding:12px;">Số Điện Thoại</th>
                        <th style="padding:12px;">Các lớp đang tham gia</th>
                        <th style="padding:12px; text-align:center; width:150px;">Thao tác</th>
                    </tr>
                </thead>
                <tbody id="studentTableBody">
                    <?php foreach ($students as $s):
                        $joinedClasses = $joinedClassesByStudent[(int)$s['id']] ?? [];
                    ?>
                    <tr class="student-row">
                        <td>#<?= $s['id'] ?></td>
                        <td class="student-name"><strong><?= htmlspecialchars($s['student_name']) ?></strong></td>
                        <td class="student-phone"><?= htmlspecialchars($s['phone']) ?></td>
                        <td>
                            <?= !empty($joinedClasses) ? implode(', ', array_map('htmlspecialchars', $joinedClasses)) : '<span style="color:gray; font-style:italic;">Chưa tham gia lớp nào</span>' ?>
                        </td>
                        <td class="student-action-links">
                            <button type="button" class="btn" style="padding: 6px 10px; font-size: 0.85rem;"
                                    onclick="openEditModal(<?= $s['id'] ?>, '<?= htmlspecialchars($s['student_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($s['phone'], ENT_QUOTES) ?>')">Sửa</button>
                            <a href="manage_students.php?delete_student_id=<?= $s['id'] ?>" class="btn-delete" style="padding: 6px 10px; font-size: 0.85rem;"
                               onclick="return confirm('Bạn chắc chắn muốn xóa học viên này?')">Xóa</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="noResultsMessage" class="no-results" style="display: none;">Không tìm thấy học viên nào phù hợp.</div>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.style.display = 'flex';
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.style.display = 'none';
        }

        function openEditModal(id, name, phone) {
            document.getElementById('editModalStudentId').value = id;
            document.getElementById('editModalStudentName').value = name;
            document.getElementById('editModalPhone').value = phone;
            openModal('editStudentModal');
        }

        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('custom-modal')) {
                e.target.style.display = 'none';
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('studentSearchInput');
            const clearBtn = document.getElementById('clearSearchBtn');
            const tableRows = document.querySelectorAll('.student-row');
            const noResultsMessage = document.getElementById('noResultsMessage');
            const studentTable = document.getElementById('studentTable');

            searchInput.addEventListener('input', function() {
                const query = searchInput.value.toLowerCase().trim();
                let hasResults = false;
                clearBtn.style.display = query.length > 0 ? 'inline-block' : 'none';

                tableRows.forEach(row => {
                    const name = row.querySelector('.student-name').innerText.toLowerCase();
                    const phone = row.querySelector('.student-phone').innerText.toLowerCase();
                    if (name.includes(query) || phone.includes(query)) {
                        row.style.display = '';
                        hasResults = true;
                    } else {
                        row.style.display = 'none';
                    }
                });

                if (hasResults) {
                    noResultsMessage.style.display = 'none';
                    studentTable.style.display = 'table';
                } else {
                    noResultsMessage.style.display = 'block';
                    studentTable.style.display = 'none';
                }
            });

            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                noResultsMessage.style.display = 'none';
                studentTable.style.display = 'table';
                tableRows.forEach(row => row.style.display = '');
            });
        });
    </script>
</body>
</html>
