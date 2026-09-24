import { Route, Routes } from 'react-router-dom';
import { MemberPortal } from './components/MemberPortal';
import { Notifications } from './components/Notifications';
import { AuthForm } from './components/AuthForm';
import { StudioShell } from './components/StudioShell';
export default function App() {
    return (
        <Routes>
            <Route
                path="/activate"
                element={<AuthForm key="activate" mode="activate" member />}
            />
            <Route
                path="/member/login"
                element={<AuthForm key="member-login" member />}
            />
            <Route
                path="/member/forgot-password"
                element={<AuthForm key="member-forgot" mode="forgot" member />}
            />
            <Route
                path="/member/reset-password"
                element={<AuthForm key="member-reset" mode="reset" member />}
            />
            <Route path="/member/*" element={<MemberPortal />} />
            <Route path="/login" element={<AuthForm key="login" />} />
            <Route
                path="/forgot-password"
                element={<AuthForm key="forgot" mode="forgot" />}
            />
            <Route
                path="/reset-password"
                element={<AuthForm key="reset" mode="reset" />}
            />
            <Route path="/notifications" element={<Notifications />} />
            <Route path="*" element={<StudioShell />} />
        </Routes>
    );
}
